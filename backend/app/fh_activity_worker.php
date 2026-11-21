<?php
declare(strict_types=1);
/**
 * Bounded CLI-only outbox synchronization, run via docker exec php php ...
 *
 * R2.1 — the run has two phases:
 *   1) publish VERIFIED records to the cloud. A record whose outcome is still unknown
 *      ('intent' / 'unresolved') is never published - it stays open and keeps being
 *      counted, so the gap is visible instead of being hidden.
 *   2) reconcile pending markers and open records under the lead lock.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth.php';
require_once __DIR__.'/fh12_prod.php';
require_once __DIR__.'/fh_activity_history.php';
$root=FH_ACT_QUEUE_ROOT;
if(is_link($root)||(file_exists($root)&&!is_dir($root))){fwrite(STDERR,"queue unavailable\n");exit(2);}

$reconciled=['scanned'=>0,'verified'=>0,'aborted'=>0,'unresolved'=>0];
$startedAt=hrtime(true);
$publishUntil=$startedAt+100000000000;
$markersUntil=$startedAt+175000000000;
$reconcileUntil=$startedAt+250000000000;
$queueFiles=glob($root.'/*/*.json')?:[];
$oldest=null;
foreach($queueFiles as $file){
    if(is_link($file))continue;
    $raw=@file_get_contents($file,false,null,0,4097);
    if(!is_string($raw)||strlen($raw)>4096)continue;
    $row=json_decode($raw,true);
    if(!is_array($row)||!in_array($row['status']??'',FH_ACT_OPEN_STATES,true))continue;
    $prepared=$row['prepared_at']??null;
    if(is_int($prepared)&&$prepared>0)$oldest=min($oldest??$prepared,$prepared);
}
$count=0;$ok=0;$err=0;
foreach($queueFiles as $file){
    // Two directory checks, PUT and readback use at most six 7-second calls.
    if($count>=12||hrtime(true)>$publishUntil-42000000000)break;
    if(is_link($file)||!is_file($file)||filesize($file)>4096)continue;
    $raw=@file_get_contents($file);if(!is_string($raw))continue;
    $row=json_decode($raw,true);
    if(!is_array($row)||($row['status']??'')!==FH_ACT_ST_VERIFIED||!is_string($row['lead_id']??null)||!is_string($row['operation_id']??null))continue;
    $lead=$row['lead_id'];$id=$row['operation_id'];
    try{fh12_lead($lead);fh_act_valid_id($id);}catch(Throwable){continue;}
    // Directory must match lead hash: forged/misplaced queues are skipped.
    if(basename(dirname($file))!==hash('sha256',$lead)||basename($file)!==$id.'.json')continue;
    if((int)($row['publish_after']??0)>time())continue;
    $count++;
    if(fh_act_flush_one($lead,$id))$ok++;
    else {
        $err++;
        $current=fh_act_record_read($lead,$id);
        if(($current['status']??'')===FH_ACT_ST_VERIFIED){
            $attempts=min(8,(int)($current['publish_attempts']??0)+1);
            $current['publish_attempts']=$attempts;
            $current['publish_after']=time()+min(3600,300*2**($attempts-1));
            try { fh_act_write_atomic($file,$current); } catch(Throwable) {}
        }
    }
}

$markers=['scanned'=>0,'committed'=>0,'aborted'=>0,'blocked'=>0];
foreach(glob(fh12_state_base().'/*/pending*.json')?:[] as $file){
    if(hrtime(true)>=$markersUntil-1000000000)break;
    $dir=dirname($file);
    if(is_link($dir)||is_link($file)||!is_file($file))continue;
    $mtime=@filemtime($file);
    if($mtime===false||time()-$mtime<FH12_PENDING_GRACE)continue;
    $raw=@file_get_contents($file,false,null,0,4097);
    if(!is_string($raw)||strlen($raw)>4096)continue;
    $pending=json_decode($raw,true);
    if(!is_array($pending)||!fh12_pending_valid($pending)||
       !preg_match('~^/CRM/([1-9][0-9]{0,17})/~D',$pending['destination'],$identity))continue;
    $lead=$identity[1];
    $category=fh12_marker_category($pending);
    if($category===''||basename($dir)!==hash('sha256',$lead)||
       !in_array($file,[fh12_pending_file($dir,$category),fh12_pending_legacy($dir)],true))continue;
    try { $lock=fh12_lock_lead($dir,0); }
    catch(Throwable) { continue; }
    try {
        clearstatcache(true,$file);
        if(is_link($file)||@file_get_contents($file,false,null,0,4097)!==$raw)continue;
        $row=fh_act_record_read($lead,$pending['id']);
        if(in_array($row['status']??'',[FH_ACT_ST_INTENT,FH_ACT_ST_UNRESOLVED],true))continue;
        $markers['scanned']++;
        try { $outcome=fh12_resolve_pending($dir,$file,$lead,$pending['actor'],stopAt:$markersUntil); }
        catch(Throwable) { $outcome='blocked'; }
        if($outcome==='blocked')@touch($file);
        $markers[$outcome]++;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

$visited=[];
foreach($queueFiles as $file){
    if(hrtime(true)>=$reconcileUntil-1000000000)break;
    $leadDir=dirname($file);
    if(isset($visited[$leadDir])||is_link($leadDir)||is_link($file))continue;
    $raw=@file_get_contents($file,false,null,0,4097);
    if(!is_string($raw)||strlen($raw)>4096)continue;
    $row=json_decode($raw,true);
    $lead=$row['lead_id']??null;
    if(!is_string($lead)||!preg_match('/^[1-9][0-9]{0,17}$/D',$lead)||
       basename($leadDir)!==hash('sha256',$lead))continue;
    $visited[$leadDir]=true;
    $r=fh_act_reconcile_orphans($lead,5,$reconcileUntil);
    foreach($r as $key=>$value)$reconciled[$key]+=$value;
}

// Retention of CLOSED local records. Only 'aborted' records are removed: they were
// never published and cannot become a successful event. Cloud objects are NOT deleted
// by this module - the retention of the cloud history is a storage-side decision.
$pruned=0;$cut=time()-(FH_ACT_RETENTION_DAYS*86400);
foreach($queueFiles as $file){
    if(hrtime(true)>=$startedAt+270000000000)break;
    if(is_link($file)||!is_file($file))continue;
    $raw=@file_get_contents($file,false,null,0,4097);
    if(!is_string($raw))continue;
    $row=json_decode($raw,true);
    if(!is_array($row)||($row['status']??'')!==FH_ACT_ST_ABORTED)continue;
    if((int)@filemtime($file)>$cut)continue;
    if(@unlink($file))$pruned++;
}

echo json_encode([
    'reconciled'=>$reconciled,
    'markers'=>$markers,
    'scanned'=>$count,'synced'=>$ok,'pending'=>$err,
    'oldest_open_seconds'=>$oldest===null?0:max(0,time()-$oldest),
    'pruned_closed'=>$pruned,
],JSON_THROW_ON_ERROR)."\n";
