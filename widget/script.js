define(['jquery'], function ($) {
  // Private to this AMD module. Session bearer tokens never enter localStorage,
  // client events, logs or globals; per-context sessionStorage preserves same-tab sessions. Live same-origin tabs may share only on authenticated context-matched request.
  var fhAuthShared = Object.create(null);
  var FH_PEER_REQUEST_WAIT_MS = 5000; // Bounded discovery of another live tab; no persistent bearer in localStorage.
  var FH_PEER_REQUEST_OFFSETS_MS = [0, 700, 1600, 3000, 4400];
  var FH_PEER_RECOVERY_MIN_MS = 10000;
  var FH_PEER_RECOVERY_MAX_MS = 60000;
  var FH_REFRESH_EARLY_S = 120; // Refresh before the server idle TTL (600 s) expires.
  var FH_REFRESH_RETRY_MS = 35000; // Backend enforces >= 35 s between refreshes.
  var FH_JWT_ATTEMPT_GUARD_MS = 31 * 60 * 1000;
  // Пауза между повторными попытками авторизации, когда маркер попытки ещё свежий
  // (сессии нет ни в своей вкладке, ни у донора). Не чаще — иначе клиент
  // долбит сервер одноразовыми JWT.
  var FH_REAUTH_RETRY_COOLDOWN_MS = 5 * 60 * 1000;
  var FH_REAUTH_TRANSPORT_RETRY_MS = 20 * 1000;
  var FH_AUTH_LOCK_WAIT_MS = 15000;
  var FH_STORAGE_PREFIX = 'czfh_auth_attempt_v3:'; // Retain consumed-JWT markers from 2.8.6.
  var FH_SESSION_STORAGE_PREFIX = 'czfh_session_v4:';
  var CustomWidget = function () {
    var self = this;

    this.callbacks = {
      settings: function ($modal_body) { return true; },
      init: function () { return true; },
      bind_actions: function () { return true; },
      render: function () { return true; },
      destroy: function () {},
      onSave: function () { return true; }
    };

    try {
      function t(key, fallback){ try { var v = self.i18n && self.i18n('userLang.'+key); if (v) return v; } catch(e){} return fallback || key; }
      function safeValue(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch]; }); }

      var IDS = {
        root:'czfh_root', tabs:'czfh_tabs',
        drop:'czfh_drop', btn:'czfh_btn', progress:'czfh_progress', fill:'czfh_fill', list:'czfh_list',
        trashBtn:'czfh_trash_btn', historyBtn:'czfh_history_btn', davBtn:'czfh_dav_btn', modal:'czfh_modal', modalClose:'czfh_modal_close', modalTitle:'czfh_modal_title', modalFrame:'czfh_modal_iframe'
      };
      var CATS = [
        { key:'photos',   titleKey:'cat_photos', title:'Снимки' },
        { key:'tickets',  titleKey:'cat_tickets', title:'Билеты' },
        { key:'report',   titleKey:'cat_report', title:'Отчет' },
        { key:'calls',    titleKey:'cat_calls', title:'Разговоры' },
        { key:'passport', titleKey:'cat_passport', title:'Паспорт' },
        { key:'other',    titleKey:'cat_other', title:'Прочее' }
      ];
      var CANARY_LEAD = '34228264';
      var CANARY_API = 'https://filehub.ivan83u.ru/wp-json/czfh/v1';
      var refreshTimer = null; // No timer survives widget destroy or context change.
      var canarySessionWait = null;
      var peerChannel=null,peerContext=null,peerPending=null;
      var peerRecoveryTimer=null,peerRecoveryDelay=FH_PEER_RECOVERY_MIN_MS;
      var authErrorVisible=false;
      var destroyed = false;
      var sidebarObserver = null;
      var sidebarTimer = null;
      var sidebarHandler = null;
      var viewGeneration = 0;
      var uploadQueueState = null;
      var telemetryEvents = [];
      var telemetryTimer=null, telemetrySending=false, telemetryRetryDelay=5000;
      var telemetryBatchLimit=10;
      var telemetryEventCounter=0;
      var telemetryQueueCounts=Object.create(null);
      var telemetryLifecycleInstalled=false;
      var telemetryVisibilityHandler=null,telemetryPageHideHandler=null,telemetryOnlineHandler=null;
      var TELEMETRY_QUEUE_PREFIX='czfh_telemetry_error_v1:';
      var TELEMETRY_LEGACY_QUEUE_KEY='czfh_telemetry_queue_v1';
      var TELEMETRY_QUEUE_TTL_MS=14*24*60*60*1000;
      var TELEMETRY_QUEUE_MAX=5000;
      var TELEMETRY_QUARANTINE_PREFIX='czfh_telemetry_quarantine_v1:';
      var TELEMETRY_QUARANTINE_MAX=1000;
      var TELEMETRY_MAX_BODY_BYTES=7800;
      var TELEMETRY_FLUSH_JITTER_MS=1200;
      var TELEMETRY_ENABLED = true; // Requires R2 /client-events; failures never affect FileHub operations.
      var TELEMETRY_ALLOWED = {
        widget_render_failed:1, context_missing:1, session_attempt:1, session_issued:1,
        session_failed:1, session_reauth_blocked:1, request_failed:1, request_timeout:1,
        upload_verified:1, upload_ambiguous:1, upload_paused:1, list_failed:1,
        trash_failed:1, calls_failed:1, thumb_failed:1, image_preview_failed:1
      };
      var savedTelemetry=[];
      try{
        var legacyQueue=JSON.parse(sessionStorage.getItem(TELEMETRY_LEGACY_QUEUE_KEY)||'[]');
        if(Array.isArray(legacyQueue))savedTelemetry=legacyQueue.filter(function(row){
          return row&&TELEMETRY_ALLOWED[row.event]&&typeof row.event_id==='string';
        }).slice(-100);
      }catch(e){}
      function newCorrId(){
        if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
        return 'no-crypto'; // No pseudo-random identifiers based on Math.random for security logging.
      }
      function newTelemetryEventId(){
        var randomId=window.crypto&&typeof window.crypto.randomUUID==='function'?window.crypto.randomUUID():'';
        if(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(randomId))return randomId;
        telemetryEventCounter++;
        var clock=window.performance&&Number.isFinite(window.performance.now())?Math.floor(window.performance.now()*1000):0;
        return 'evt_'+Date.now().toString(36)+'_'+clock.toString(36)+'_'+telemetryEventCounter.toString(36);
      }
      function telemetryQueueKey(operatorId,eventId){
        if(!/^[1-9][0-9]{0,17}$/.test(String(operatorId||''))||!/^[a-z0-9_-]{8,64}$/i.test(String(eventId||'')))return '';
        return TELEMETRY_QUEUE_PREFIX+operatorId+':'+eventId;
      }
      function telemetryQueueCount(operatorId){
        if(Number.isInteger(telemetryQueueCounts[operatorId]))return telemetryQueueCounts[operatorId];
        var prefix=TELEMETRY_QUEUE_PREFIX+operatorId+':',count=0;
        for(var i=0;i<localStorage.length;i++){var storedKey=localStorage.key(i);if(storedKey&&storedKey.indexOf(prefix)===0)count++;}
        telemetryQueueCounts[operatorId]=count;
        return count;
      }
      function persistTelemetryEvent(event){
        var key=telemetryQueueKey(event.operator_id,event.event_id);
        if(!key)return false;
        try{
          var exists=localStorage.getItem(key)!==null;
          if(!exists&&telemetryQueueCount(event.operator_id)>=TELEMETRY_QUEUE_MAX)return false;
          localStorage.setItem(key,JSON.stringify({stored_at_ms:Date.now(),event:event}));
          if(!exists)telemetryQueueCounts[event.operator_id]=telemetryQueueCount(event.operator_id)+1;
          return true;
        }catch(e){return false;}
      }
      function loadPersistentTelemetry(operatorId){
        var prefix=TELEMETRY_QUEUE_PREFIX+operatorId+':',now=Date.now(),loaded=[];
        try{
          for(var i=localStorage.length-1;i>=0;i--){
            var key=localStorage.key(i);
            if(!key||key.indexOf(prefix)!==0)continue;
            try{
              var saved=JSON.parse(localStorage.getItem(key)||'null');
              if(!saved||!Number.isFinite(saved.stored_at_ms)||now-saved.stored_at_ms>TELEMETRY_QUEUE_TTL_MS||saved.stored_at_ms>now+300000){
                localStorage.removeItem(key);continue;
              }
              var row=saved.event;
              if(row&&row.operator_id===operatorId&&TELEMETRY_ALLOWED[row.event]&&typeof row.event_id==='string')loaded.push(row);
              else localStorage.removeItem(key);
            }catch(e){localStorage.removeItem(key);}
          }
        }catch(e){}
        var known=Object.create(null);
        telemetryEvents.forEach(function(row){known[row.event_id]=true;});
        loaded.forEach(function(row){if(!known[row.event_id]){telemetryEvents.push(row);known[row.event_id]=true;}});
        telemetryQueueCounts[operatorId]=loaded.length;
      }
      function telemetryPayloadBytes(events){
        var payload=JSON.stringify({schema_version:1,events:events});
        try{
          if(window.TextEncoder)return new window.TextEncoder().encode(payload).length;
          return unescape(encodeURIComponent(payload)).length;
        }catch(e){return payload.length;}
      }
      function telemetryBatchFor(operatorId){
        var batch=[];
        var candidates=telemetryEvents.filter(function(event){return event.operator_id===operatorId;});
        for(var i=0;i<candidates.length&&batch.length<telemetryBatchLimit;i++){
          var trial=batch.concat([candidates[i]]);
          if(telemetryPayloadBytes(trial)>TELEMETRY_MAX_BODY_BYTES){
            if(!batch.length){
              // Keep the row for diagnostics, but move it out of the delivery
              // queue. Never delete it merely because it cannot fit one request.
              if(quarantineTelemetryEvent(candidates[i],'oversized'))removePersistedTelemetry(candidates[i]);
              removeTelemetryFromMemory(candidates[i]);
              continue;
            }
            break;
          }
          batch=trial;
        }
        return batch;
      }
      function prunePersistentTelemetry(){
        var now=Date.now();
        try{
          for(var i=localStorage.length-1;i>=0;i--){
            var key=localStorage.key(i);
            if(!key||key.indexOf(TELEMETRY_QUEUE_PREFIX)!==0)continue;
            try{
              var saved=JSON.parse(localStorage.getItem(key)||'null');
              if(!saved||!Number.isFinite(saved.stored_at_ms)||now-saved.stored_at_ms>TELEMETRY_QUEUE_TTL_MS||saved.stored_at_ms>now+300000)
                localStorage.removeItem(key);
            }catch(e){localStorage.removeItem(key);}
          }
        }catch(e){}
        pruneTelemetryQuarantine();
        telemetryQueueCounts=Object.create(null);
      }
      function removePersistedTelemetry(event){
        var key=telemetryQueueKey(event.operator_id,event.event_id);
        if(key)try{
          var existed=localStorage.getItem(key)!==null;
          localStorage.removeItem(key);
          if(existed&&Number.isInteger(telemetryQueueCounts[event.operator_id]))
            telemetryQueueCounts[event.operator_id]=Math.max(0,telemetryQueueCounts[event.operator_id]-1);
        }catch(e){}
      }
      function removeTelemetryFromMemory(event){
        telemetryEvents=telemetryEvents.filter(function(row){return row.event_id!==event.event_id;});
      }
      function telemetryQuarantineKey(operatorId,eventId){
        if(!/^[1-9][0-9]{0,17}$/.test(String(operatorId||''))||!/^[a-z0-9_-]{8,64}$/i.test(String(eventId||'')))return '';
        return TELEMETRY_QUARANTINE_PREFIX+operatorId+':'+eventId;
      }
      function pruneTelemetryQuarantine(){
        var now=Date.now(),rows=[];
        try{
          for(var i=localStorage.length-1;i>=0;i--){
            var key=localStorage.key(i);
            if(!key||key.indexOf(TELEMETRY_QUARANTINE_PREFIX)!==0)continue;
            try{
              var saved=JSON.parse(localStorage.getItem(key)||'null');
              if(!saved||!Number.isFinite(saved.stored_at_ms)||now-saved.stored_at_ms>TELEMETRY_QUEUE_TTL_MS||saved.stored_at_ms>now+300000){
                localStorage.removeItem(key);continue;
              }
              rows.push({key:key,stored_at_ms:saved.stored_at_ms});
            }catch(e){localStorage.removeItem(key);}
          }
          if(rows.length>TELEMETRY_QUARANTINE_MAX){
            rows.sort(function(a,b){return a.stored_at_ms-b.stored_at_ms;});
            for(var j=0;j<rows.length-TELEMETRY_QUARANTINE_MAX;j++)localStorage.removeItem(rows[j].key);
          }
        }catch(e){}
      }
      function quarantineTelemetryEvent(event,reason){
        var key=telemetryQuarantineKey(event&&event.operator_id,event&&event.event_id);
        if(!key)return false;
        var code=/^[a-z0-9_]{1,32}$/.test(String(reason||''))?String(reason):'rejected';
        try{
          localStorage.setItem(key,JSON.stringify({stored_at_ms:Date.now(),reason:code,event:event}));
          pruneTelemetryQuarantine();
          return true;
        }catch(e){return false;}
      }
      function quarantineTelemetryBatch(events,reason){
        events.forEach(function(event){
          // A move to quarantine is not a destructive acknowledgement: the
          // diagnostic row remains in bounded, TTL-based storage.
          if(quarantineTelemetryEvent(event,reason))removePersistedTelemetry(event);
          removeTelemetryFromMemory(event);
        });
      }
      telemetryEvents=savedTelemetry.slice();
      var legacyQueueMigrated=true;
      savedTelemetry.forEach(function(row){if(!persistTelemetryEvent(row))legacyQueueMigrated=false;});
      if(savedTelemetry.length&&legacyQueueMigrated)try{sessionStorage.removeItem(TELEMETRY_LEGACY_QUEUE_KEY);}catch(e){}
      prunePersistentTelemetry();
      function telemetry(event, details){
        if (!TELEMETRY_ALLOWED[event]) return;
        details=details||{};
        // Never include exception text, names, URLs, JWTs, bearer tokens or account IDs.
        var feature=/^(auth|upload|files|trash|calls|preview|widget)$/.test(details.feature||'') ? details.feature : 'widget';
        var status=Number(details.http_status);
        var context=details.context||captureTelemetryContext();
        var operatorId=String(context.operator_id||'');
        var leadId=String(context.lead_id==null?'':context.lead_id);
        var category=String(context.category||'');
        var errorCode=/^[a-z0-9_-]{1,32}$/.test(details.error_code||'')?details.error_code:'';
        var row={schema_version:1,widget_version:'2.8.21',event:event,feature:feature,
          http_status:Number.isInteger(status)&&status>=100&&status<=599?status:0,
          corr_id:/^[a-z0-9-]{7,64}$/i.test(details.corr_id||'')?details.corr_id:newCorrId(),
          duration_ms:Math.max(0,Math.min(600000,Math.round(Number(details.duration_ms)||0))),
          client_time_ms:Date.now()};
        row.event_id=newTelemetryEventId();
        if(/^[1-9][0-9]{0,17}$/.test(operatorId))row.operator_id=operatorId;
        if(errorCode){
          row.error_code=errorCode;
          if(/^[1-9][0-9]{0,17}$/.test(leadId))row.lead_id=leadId;
          if(/^(passport|tickets|photos|other|calls|report|ai)$/.test(category))row.category=category;
        }
        telemetryEvents.push(row);
        if(telemetryEvents.length>TELEMETRY_QUEUE_MAX)telemetryEvents.shift();
        row.queue_remaining=Math.max(0,telemetryEvents.length-1);
        persistTelemetryEvent(row);
        // Only structured allowlisted data. Never send tokens, names, URLs or exceptions.
        if(TELEMETRY_ENABLED && !telemetryTimer)
          telemetryTimer=setTimeout(function(){flushTelemetry();},3500+telemetryJitterMs());
      }
      function telemetryJitterMs(){
        try{
          if(window.crypto&&typeof window.crypto.getRandomValues==='function'){
            var value=new Uint32Array(1);window.crypto.getRandomValues(value);
            return value[0]%TELEMETRY_FLUSH_JITTER_MS;
          }
        }catch(e){}
        return Math.floor(Date.now()%TELEMETRY_FLUSH_JITTER_MS);
      }
      function telemetryRetryPolicy(status,transportError){
        if(transportError)return 'retry';
        if(status===401)return 'session';
        if(status===400||status===413)return 'split';
        if(status===408||status===425||status===429||status>=500)return 'retry';
        if(status>=400)return 'reject';
        return 'retry';
      }
      function telemetryRetryAfterMs(response){
        try{
          var raw=response&&response.headers&&response.headers.get('Retry-After');
          if(/^[0-9]{1,3}$/.test(raw||''))return Math.min(60000,Math.max(500,1000*Number(raw)));
        }catch(e){}
        return 0;
      }
      function sendTelemetryBatch(operatorId,session,eventContext,keepalive){
        var batch=telemetryBatchFor(operatorId);
        if(!batch.length)return Promise.resolve();
        telemetrySending=true;
        var controller=typeof AbortController==='function'?new AbortController():null;
        var guard=controller?setTimeout(function(){controller.abort();},8000):null;
        var headers={'Content-Type':'application/json','X-FH-Session':session.token};
        // Stable event ids let operators identify a duplicate if the 204 response is lost.
        var retryable=false,awaitNewSession=false,removeBatch=false,quarantineBatch=false,
            splitBatch=false,serverRetryMs=0,responseStatus=0;
        return fetch(CANARY_API+'/client-events',{method:'POST',mode:'cors',credentials:'omit',
          headers:headers,body:JSON.stringify({schema_version:1,events:batch}),
          signal:controller?controller.signal:undefined,cache:'no-store',keepalive:!!keepalive})
          .then(function(response){
            if(response.ok){telemetryRetryDelay=5000;telemetryBatchLimit=10;removeBatch=true;return;}
            responseStatus=Number(response.status)||0;
            var policy=telemetryRetryPolicy(response.status,false);
            retryable=policy==='retry'||policy==='split';
            awaitNewSession=policy==='session';
            splitBatch=policy==='split'&&batch.length>1;
            quarantineBatch=policy==='reject'||(policy==='split'&&batch.length===1);
            if(response.status===429){
              serverRetryMs=telemetryRetryAfterMs(response);
              return response.json().catch(function(){return {};}).then(function(body){
                // Both the edge and the backend use bounded machine-readable codes.
                if(eventContext&&body&&typeof body.error==='string'&&
                   /^[a-z0-9_]{1,64}$/.test(body.error))eventContext.rate_limit_code=body.error;
              });
            }
          })
          .catch(function(){retryable=telemetryRetryPolicy(0,true)==='retry';})
          .then(function(){
            if(guard!==null)clearTimeout(guard);
            telemetrySending=false;
            if(removeBatch){
              var completed=Object.create(null);
              batch.forEach(function(event){completed[event.event_id]=true;removePersistedTelemetry(event);});
              for(var i=telemetryEvents.length-1;i>=0;i--)
                if(completed[telemetryEvents[i].event_id])telemetryEvents.splice(i,1);
            }
            if(quarantineBatch)quarantineTelemetryBatch(batch,'http_'+responseStatus);
            if(splitBatch)telemetryBatchLimit=Math.max(1,Math.floor(batch.length/2));
            else if(quarantineBatch)telemetryBatchLimit=10;
            if(retryable&&!awaitNewSession)
              telemetryRetryDelay=serverRetryMs||Math.min(telemetryRetryDelay*2,60000);
            if(telemetryEvents.length&&!telemetryTimer&&!awaitNewSession)
              telemetryTimer=setTimeout(function(){flushTelemetry();},retryable?telemetryRetryDelay:5000);
          });
      }
      function flushTelemetry(options){
        options=options||{};
        if(telemetryTimer!==null){clearTimeout(telemetryTimer);telemetryTimer=null;}
        if(!TELEMETRY_ENABLED||(destroyed&&!options.final)||!state.contextKey||!state.production)return;
        // Never create or refresh a session to send diagnostics.
        var eventContext=captureTelemetryContext(state.contextKey);
        var operatorId=eventContext.operator_id;
        if(!operatorId)return;
        // The persistent queue is the source of truth across card closes and browser restarts.
        loadPersistentTelemetry(operatorId);
        if(!telemetryEvents.length)return;
        function run(){
          if(!TELEMETRY_ENABLED||(destroyed&&!options.final)||!state.contextKey||!state.production)return;
          loadPersistentTelemetry(operatorId);
          if(telemetrySending||!telemetryEvents.length)return Promise.resolve();
          var session=knownCanarySession(state.contextKey);
          if(!session.token||session.expires<=Math.floor(Date.now()/1000)+10)return Promise.resolve();
          // Return the fetch promise so a Web Lock is held until the batch is
          // acknowledged and removed from localStorage. Releasing the lock when
          // fetch starts lets other tabs read and send the same queue.
          return sendTelemetryBatch(operatorId,session,eventContext,!!options.keepalive);
        }
        // pagehide/destroy must initiate keepalive synchronously. Web Locks are a
        // useful cross-tab guard for normal flushes, but acquiring one is async
        // and may lose the browser's unload window.
        if(options.direct){
          var directPromise=run();
          if(directPromise&&typeof directPromise.catch==='function')directPromise.catch(function(){});
          return;
        }
        if(navigator.locks&&typeof navigator.locks.request==='function'){
          try{
            var lockPromise=navigator.locks.request('czfh-telemetry-flush-v1:'+state.contextKey,
              {mode:'exclusive',ifAvailable:!!options.final},run);
            if(lockPromise&&typeof lockPromise.catch==='function')lockPromise.catch(function(){});
            return;
          }catch(e){}
        }
        var fallbackPromise=run();
        if(fallbackPromise&&typeof fallbackPromise.catch==='function')fallbackPromise.catch(function(){});
      }
      function scheduleTelemetryFlush(delay){
        if(destroyed||!TELEMETRY_ENABLED||telemetryTimer!==null)return;
        var wait=Math.max(0,Number(delay)||0);
        if(wait<100)wait+=telemetryJitterMs();
        telemetryTimer=setTimeout(function(){flushTelemetry();},wait);
      }
      function resumeWidgetSession(){
        if(!contextIsCurrent(state.contextKey))return;
        var eventContext=captureTelemetryContext(state.contextKey);
        if(eventContext.operator_id)loadPersistentTelemetry(eventContext.operator_id);
        scheduleTelemetryFlush(0);
        if(navigator.onLine===false)return;
        var entry=knownCanarySession(state.contextKey),now=Math.floor(Date.now()/1000);
        // A page becoming visible must never spend a new one-shot amoCRM JWT by
        // itself. It may, however, discover a session that appeared in another tab
        // after this card was opened.
        if(!peerSessionLive(entry,now)){
          peerRecoveryDelay=FH_PEER_RECOVERY_MIN_MS;
          schedulePeerRecovery(0);
          return;
        }
        ensureCanarySession(eventContext).catch(function(){});
      }
      function removeTelemetryLifecycle(){
        if(telemetryVisibilityHandler){document.removeEventListener('visibilitychange',telemetryVisibilityHandler);telemetryVisibilityHandler=null;}
        if(telemetryPageHideHandler){window.removeEventListener('pagehide',telemetryPageHideHandler);telemetryPageHideHandler=null;}
        if(telemetryOnlineHandler){window.removeEventListener('online',telemetryOnlineHandler);telemetryOnlineHandler=null;}
        telemetryLifecycleInstalled=false;
      }
      function installTelemetryLifecycle(){
        if(telemetryLifecycleInstalled)return;
        telemetryVisibilityHandler=function(){
          if(document.visibilityState==='visible')resumeWidgetSession();
          else flushTelemetry({keepalive:true});
        };
        telemetryPageHideHandler=function(){flushTelemetry({keepalive:true,final:true,direct:true});};
        telemetryOnlineHandler=function(){resumeWidgetSession();};
        document.addEventListener('visibilitychange',telemetryVisibilityHandler);
        window.addEventListener('pagehide',telemetryPageHideHandler);
        window.addEventListener('online',telemetryOnlineHandler);
        telemetryLifecycleInstalled=true;
      }
      function fhError(code,status,ambiguous){
        var message={
          context_missing:'Не удалось подтвердить пользователя и аккаунт amoCRM. Обновите карточку.',
          auth_storage_unavailable:'Браузер не предоставляет безопасное хранилище авторизации.',
          auth_lock_unavailable:'Браузер не поддерживает безопасную блокировку авторизации.',
          auth_lock_timeout:'Другая вкладка долго обновляет сессию FileHub. Повторите через несколько секунд.',
          session_reauth_blocked:'Нет действующей FileHub-сессии. Если другая вкладка с FileHub открыта, обновите карточку. Иначе потребуется новая авторизация amoCRM с новым JWT.',
          session_unavailable:'Не удалось получить FileHub-сессию.',
          session_expired:'Сессия FileHub завершилась. Требуется новый JWT amoCRM; ранее использованный JWT отправлять повторно нельзя.',
          session_absolute_expired:'Достигнут абсолютный срок FileHub-сессии (до 30 минут). Требуется новая авторизация amoCRM.',
          session_refresh_failed:'Не удалось продлить действующую FileHub-сессию. Повторная выдача по старому JWT запрещена.',
          session_refreshable:'Сессия FileHub устарела, но ещё может быть продлена.',
          session_refresh_throttled:'Сервер просит подождать перед продлением сессии. Сессия ещё жива — повторите через минуту.',
          session_revoked:'Сессия FileHub заменена другой вкладкой. Обновите карточку.',
          amo_session_denied:'amoCRM не принял авторизацию. Требуется повторный вход в amoCRM.',
          session_invalid:'Сервер вернул некорректную FileHub-сессию.',
          write_busy:'Файлы сейчас обновляются. Повторите через несколько секунд.',
          reconciliation_required:'FileHub уточняет результат предыдущего изменения. Проверьте файлы и корзину перед повтором.',
          request_timeout:'Время ожидания сервера истекло. Проверьте статус операции перед повтором.',
          network_error:'Нет ответа от сервера. Проверьте соединение и состояние операции.'
        };
        // Код ошибки приходит от сервера, поэтому обращение идёт только к СОБСТВЕННЫМ
        // свойствам карты: иначе 'constructor'/'toString' вернули бы в сообщение
        // функцию прототипа вместо текста ошибки.
        var text=Object.prototype.hasOwnProperty.call(message,code)?message[code]:null;
        var e=new Error(text||('Ошибка FileHub'+(status?' (HTTP '+status+')':'')));
        e.code=code;e.status=status||0;e.ambiguous=!!ambiguous;
        return e;
      }
      function resolveContext(){
        function ident(x){var t=String(x==null?'':x);return /^[1-9][0-9]{0,17}$/.test(t)?t:null;}
        var acc=null,user=null;
        try{if(typeof AMOCRM!=='undefined'&&AMOCRM&&typeof AMOCRM.constant==='function'){
          var a=AMOCRM.constant('account'),u=AMOCRM.constant('user');
          acc=ident(a&&(a.id||a.account_id));user=ident(u&&(u.id||u.user_id));
        }}catch(e){}
        try{if(typeof AMOCRM!=='undefined'&&AMOCRM&&AMOCRM.data){
          acc=acc||ident(AMOCRM.data.account&&(AMOCRM.data.account.id||AMOCRM.data.account.account_id));
          user=user||ident(AMOCRM.data.current_user&&(AMOCRM.data.current_user.id||AMOCRM.data.current_user.user_id));
        }}catch(e){}
        return acc&&user?'a'+acc+':u'+user:null;
      }
      function contextIsCurrent(context){
        if(!context||destroyed||state.contextKey!==context)return false;
        var current=resolveContext();
        // amoCRM may temporarily hide its constants while a card re-renders. A cached,
        // validated context is safer than discarding a still-live tab-scoped session.
        return current===null||current===context;
      }
      function resolveOperatorId(){
        function ident(x){var t=String(x==null?'':x);return /^[1-9][0-9]{0,17}$/.test(t)?t:null;}
        var user=null;
        try{if(typeof AMOCRM!=='undefined'&&AMOCRM&&typeof AMOCRM.constant==='function'){
          var u=AMOCRM.constant('user');user=ident(u&&(u.id||u.user_id));
        }}catch(e){}
        try{if(!user&&typeof AMOCRM!=='undefined'&&AMOCRM&&AMOCRM.data)
          user=ident(AMOCRM.data.current_user&&(AMOCRM.data.current_user.id||AMOCRM.data.current_user.user_id));
        }catch(e){}
        return user;
      }
      function captureTelemetryContext(contextKey){
        var explicit=typeof contextKey==='string';
        var key=explicit?contextKey:(resolveContext()||state.contextKey||'');
        var match=/^a[1-9][0-9]{0,17}:u([1-9][0-9]{0,17})$/.exec(key);
        return {
          operator_id:explicit?(match?match[1]:resolveOperatorId()):(resolveOperatorId()||(match&&match[1])),
          lead_id:String(state.leadId==null?'':state.leadId),
          category:String(state.cat||'')
        };
      }
      function peerSessionLive(entry,nowS){
        nowS=Number.isFinite(Number(nowS))?Number(nowS):Math.floor(Date.now()/1000);
        return !!(entry&&typeof entry.token==='string'&&entry.token&&
          Number.isInteger(entry.expires)&&entry.expires>nowS&&
          Number.isInteger(entry.absoluteExpires)&&entry.absoluteExpires>nowS);
      }
      function clearPeerRecovery(){
        if(peerRecoveryTimer!==null){clearTimeout(peerRecoveryTimer);peerRecoveryTimer=null;}
      }
      function schedulePeerRecovery(delay){
        if(destroyed||!state.contextKey||!state.production||navigator.onLine===false)return;
        var now=Math.floor(Date.now()/1000);
        if(peerSessionLive(knownCanarySession(state.contextKey),now)){clearPeerRecovery();return;}
        var wait=Math.max(0,Number(delay)||0);
        if(peerRecoveryTimer!==null){
          if(wait>0)return;
          clearTimeout(peerRecoveryTimer);peerRecoveryTimer=null;
        }
        peerRecoveryTimer=setTimeout(function(){
          peerRecoveryTimer=null;
          probePeerSession();
        },wait);
      }
      function probePeerSession(){
        if(!contextIsCurrent(state.contextKey)||navigator.onLine===false)return;
        var context=state.contextKey,now=Math.floor(Date.now()/1000);
        if(peerSessionLive(knownCanarySession(context),now)){peerRecoveryDelay=FH_PEER_RECOVERY_MIN_MS;return;}
        requestLivePeerSession(context).then(function(token){
          if(destroyed||state.contextKey!==context)return;
          if(token){
            peerRecoveryDelay=FH_PEER_RECOVERY_MIN_MS;
            telemetry('session_issued',{feature:'auth',error_code:'peer_recovery',
              context:captureTelemetryContext(context)});
            if(authErrorVisible&&typeof refreshCurrentCategory==='function')refreshCurrentCategory();
            return;
          }
          peerRecoveryDelay=Math.min(FH_PEER_RECOVERY_MAX_MS,
            Math.max(FH_PEER_RECOVERY_MIN_MS,peerRecoveryDelay*2));
          schedulePeerRecovery(peerRecoveryDelay);
        }).catch(function(){
          if(destroyed)return;
          peerRecoveryDelay=Math.min(FH_PEER_RECOVERY_MAX_MS,
            Math.max(FH_PEER_RECOVERY_MIN_MS,peerRecoveryDelay*2));
          schedulePeerRecovery(peerRecoveryDelay);
        });
      }
      // Share a *living* session with another tab without persisting bearer in localStorage.
      // BroadcastChannel is same-origin; channel names are per validated account/user context.
      // The bearer is sent only as a direct response to a short-lived nonce, not broadcast proactively.
      function closePeerChannel(){
        if(peerPending){
          clearTimeout(peerPending.timer);
          if(peerPending.retryTimer)clearTimeout(peerPending.retryTimer);
          peerPending.resolve(null);peerPending=null;
        }
        if(peerChannel){try{peerChannel.close();}catch(e){}peerChannel=null;}
        peerContext=null;
      }
      function ensurePeerChannel(context){
        if(peerChannel&&peerContext===context)return true;
        closePeerChannel();
        if(!context||!window||typeof window.BroadcastChannel!=='function')return false;
        try{
          var ch=new window.BroadcastChannel('czfh-live-session-v1:'+context);
          ch.onmessage=function(event){
            if(destroyed)return;
            var msg=event&&event.data;
            if(msg&&typeof msg==='object'&&msg.kind==='available'){
              var current=knownCanarySession(context),nowAvailable=Math.floor(Date.now()/1000);
              if(!peerSessionLive(current,nowAvailable))schedulePeerRecovery(0);
              return;
            }
            if(!msg||typeof msg!=='object'||!/^[-a-f0-9]{36}$/i.test(msg.nonce||''))return;
            if(msg.kind==='request'){
              var entry=knownCanarySession(context),now=Math.floor(Date.now()/1000);
              if(peerSessionLive(entry,now+5)){
                try{ch.postMessage({kind:'offer',nonce:msg.nonce,token:entry.token,
                  expires:entry.expires,absoluteExpires:entry.absoluteExpires,
                  serverTime:serverNowS()});}catch(e){}
              }
            } else if(msg.kind==='offer'&&peerPending&&msg.nonce===peerPending.nonce){
              if(!contextIsCurrent(context))return;
              // Do not trust other widget instances blindly: validate exact context, shape,
              // absolute time and bearer. The backend still authorizes every API request.
              try{
                var live=knownCanarySession(context);
                var now=Math.floor(Date.now()/1000);
                if(live.token&&live.expires>now&&
                   (msg.token!==live.token||msg.expires<=live.expires))return;
                noteServerTime(msg.serverTime);
                var token=saveCanarySession(context,msg.token,msg.expires,msg.absoluteExpires,{announce:false});
                var pending=peerPending;peerPending=null;
                clearTimeout(pending.timer);if(pending.retryTimer)clearTimeout(pending.retryTimer);
                pending.resolve(token);
              }catch(e){} // Malformed/stale offer: continue waiting until bounded timeout.
            }
          };
          peerChannel=ch;peerContext=context;
          return true;
        }catch(e){closePeerChannel();return false;}
      }
      function requestLivePeerSession(context){
        if(!ensurePeerChannel(context)||!window.crypto||typeof window.crypto.randomUUID!=='function')
          return Promise.resolve(null);
        if(peerPending)return peerPending.promise;
        var nonce=window.crypto.randomUUID();
        var resolvePending;
        var promise=new Promise(function(resolve){resolvePending=resolve;});
        var row={nonce:nonce,resolve:resolvePending,promise:promise,timer:null,retryTimer:null,attempt:0};
        peerPending=row;
        function requestOnce(){
          if(peerPending!==row)return;
          try{peerChannel.postMessage({kind:'request',nonce:nonce});}
          catch(e){return;}
          row.attempt++;
          if(row.attempt<FH_PEER_REQUEST_OFFSETS_MS.length){
            var jitter=Date.now()%251;
            row.retryTimer=setTimeout(requestOnce,
              Math.max(50,FH_PEER_REQUEST_OFFSETS_MS[row.attempt]-FH_PEER_REQUEST_OFFSETS_MS[row.attempt-1]+jitter));
          }
        }
        row.timer=setTimeout(function(){
          if(peerPending===row){
            if(row.retryTimer){clearTimeout(row.retryTimer);row.retryTimer=null;}
            peerPending=null;row.resolve(null);
          }
        },FH_PEER_REQUEST_WAIT_MS);
        requestOnce();
        return promise;
      }
      function authStateFor(context){
        if (!fhAuthShared[context]) fhAuthShared[context]={token:null,expires:0,absoluteExpires:0,inflight:null,refreshInflight:null,retryNotBefore:0};
        return fhAuthShared[context];
      }
      function storageKeyFor(context){return FH_STORAGE_PREFIX+context;}
      function knownAttempt(context){
        // localStorage is only an attempt flag, NEVER a session token or JWT.
        try{
          var raw=window.localStorage.getItem(storageKeyFor(context));
          if(!raw)return false;
          var value=JSON.parse(raw),age=Date.now()-Number(value.ts);
          if(Number.isInteger(value.absoluteExpires)&&value.absoluteExpires>0&&
             Date.now()>=value.absoluteExpires*1000)return false;
          if(age>=0&&age<FH_JWT_ATTEMPT_GUARD_MS)return true;
          // Do not remove the expired marker: another tab may have replaced it.
          return false;
        }catch(e){throw fhError('auth_storage_unavailable');}
      }
      function reserveAttempt(context,force){
        try{
          if(!force&&knownAttempt(context))return false;
          var guard={ts:Date.now(),id:newCorrId(),outcome:'pending',nextRetryAt:0};
          window.localStorage.setItem(storageKeyFor(context),JSON.stringify(guard));
          var actual=JSON.parse(window.localStorage.getItem(storageKeyFor(context))||'null');
          if(!actual||actual.id!==guard.id)return false;
          return guard.id;
        }catch(e){throw fhError('auth_storage_unavailable');}
      }
      /**
       * Разрешена ли повторная попытка авторизации, если маркер попытки ещё «свежий».
       *
       * Маркер защищает от повторной отправки ОДНОГО И ТОГО ЖЕ одноразового JWT.
       * Но живая сессия может быть в другой вкладке amoCRM, а sessionStorage
       * на вкладку — тогда вкладка-донор недоступна, и виджет без повторной
       * попытки остаётся заблокированным до 30 минут. Поэтому повторная попытка
       * разрешена, но не чаще раза в FH_REAUTH_RETRY_COOLDOWN_MS: клиент не должен
       * долбить сервер одноразовыми JWT.
       */
      function authRetryAllowed(context){
        try{
          var raw=window.localStorage.getItem(storageKeyFor(context));
          if(!raw)return true;
          var value=JSON.parse(raw);
          var age=Date.now()-Number(value.ts);
          if(value&&Number.isFinite(Number(value.nextRetryAt))&&Number(value.nextRetryAt)>0)
            return Date.now()>=Number(value.nextRetryAt);
          // A confirmed 401/denial gets the conservative five-minute cooldown.
          // A transport/5xx failure gets a short backoff so one flaky tab does not
          // freeze every other already-open amoCRM card.
          var wait=value&&value.outcome==='transport'?FH_REAUTH_TRANSPORT_RETRY_MS:FH_REAUTH_RETRY_COOLDOWN_MS;
          return !(age>=0&&age<wait);
        }catch(e){return false;}
      }
      function recordAttemptSession(context,attemptId,absoluteExpires){
        try{
          var key=storageKeyFor(context),actual=JSON.parse(window.localStorage.getItem(key)||'null');
          if(!actual||actual.id!==attemptId)return;
          actual.absoluteExpires=absoluteExpires;
          actual.outcome='issued';actual.nextRetryAt=0;
          window.localStorage.setItem(key,JSON.stringify(actual));
        }catch(e){} // Keep the conservative timestamp guard if the update cannot be stored.
      }
      function recordAttemptFailure(context,attemptId,errorCode,status){
        if(!attemptId)return;
        try{
          var key=storageKeyFor(context),actual=JSON.parse(window.localStorage.getItem(key)||'null');
          if(!actual||actual.id!==attemptId)return;
          var transport=status===0||status===429||status>=500;
          actual.outcome=transport?'transport':'failed';
          actual.errorCode=/^[a-z0-9_-]{1,32}$/.test(String(errorCode||''))?errorCode:'';
          actual.nextRetryAt=Date.now()+(transport?FH_REAUTH_TRANSPORT_RETRY_MS:FH_REAUTH_RETRY_COOLDOWN_MS);
          window.localStorage.setItem(key,JSON.stringify(actual));
        }catch(e){}
      }
      // Use native Web Locks for cross-tab atomicity. localStorage-only check/set
      // cannot provide an atomic compare-and-set and is NOT accepted as a fallback.
      function underAuthLock(context,operation){
        if(!navigator.locks||typeof navigator.locks.request!=='function')
          return Promise.reject(fhError('auth_lock_unavailable'));
        var controller=typeof AbortController==='function'?new AbortController():null;
        var timer=controller?setTimeout(function(){controller.abort();},FH_AUTH_LOCK_WAIT_MS):null;
        var options={mode:'exclusive'};
        if(controller)options.signal=controller.signal;
        var promise;
        try{promise=navigator.locks.request('czfh-auth-v3-'+context,options,operation);}
        catch(e){if(timer!==null)clearTimeout(timer);return Promise.reject(e);}
        return promise.then(function(value){if(timer!==null)clearTimeout(timer);return value;},
          function(err){
            if(timer!==null)clearTimeout(timer);
            if(err&&err.name==='AbortError')throw fhError('auth_lock_timeout');
            throw err;
          });
      }
      var state = { backend:null, leadId:null, contextKey:null, cat:'photos', canary:false, production:false, trashView:false, historyView:false, davView:false };
      var mediaLinks = Object.create(null);
      // Refresh short-lived signed media URLs without changing any server-side state.
      var mediaRefreshTimer = null;
      var mediaRefreshGeneration = 0;
      var mediaRefreshBusy = false;
      var uploadBusy = false;
      var activePreviewCleanup = null;
      var listRequestGeneration = 0;
  // Авто-обновление списка после ошибки превью.
  //
  // Раньше обработчик error у <img> вызывал loadList() напрямую. Флаг «уже
  // обновляли» жил в замыкании узла, а renderList() создаёт узлы заново — флаг
  // обнулялся, и при устойчивой ошибке превью (404/502/503) возникал бесконечный
  // цикл loadList -> render -> новая ошибка -> loadList: один открытый список
  // генерировал непрерывный поток GET /files на боевой бэкенд.
  //
  // Теперь: (1) авто-обновление разрешено ТОЛЬКО для истёкшей подписи capability
  // (истёкшую подпись чинит новый список; ошибку хранилища — нет);
  // (2) не больше одного обновления на текущий список;
  // (3) все упавшие картинки схлопываются в ОДИН запрос (дебаунс).
  var listAutoRefresh={used:false,timer:null,key:''};
  function listAutoRefreshKey(){
    var view=state.historyView?'history':(state.trashView?'trash':(state.davView?'dav':'files'));
    return String(state.leadId)+'|'+String(state.cat)+'|'+view;
  }
  function requestListAutoRefresh(){
    var key=listAutoRefreshKey();
    if(listAutoRefresh.used&&listAutoRefresh.key===key)return;
    listAutoRefresh.used=true;
    listAutoRefresh.key=key;
    if(listAutoRefresh.timer)clearTimeout(listAutoRefresh.timer);
    listAutoRefresh.timer=setTimeout(function(){
      listAutoRefresh.timer=null;
      if(destroyed)return;
      if(listAutoRefreshKey()!==key)return;
      if(state.historyView||state.trashView||state.davView)return;
      loadList();
    },1200);
  }
      function stopMediaRefresh(){
        if (mediaRefreshTimer !== null) { clearTimeout(mediaRefreshTimer); mediaRefreshTimer = null; }
        mediaRefreshGeneration++;
      }
      function signedExpiry(url){
        if (typeof url !== 'string') return 0;
        var match = /(?:[?&])exp=(\d{10,})/.exec(url);
        return match ? Number(match[1]) : 0;
      }
      function scheduleMediaRefresh(items){
        stopMediaRefresh();
        if (!state.production || state.historyView || state.trashView || state.davView || isCallsCat() || !Array.isArray(items)) return;
        var soonest = Infinity;
        items.forEach(function(it){
          if (!it || typeof it !== 'object') return;
          [it.url, it.thumbUrl, it.downloadUrl].forEach(function(url){
            var exp = signedExpiry(url);
            if (exp > 0 && exp < soonest) soonest = exp;
          });
        });
        if (!isFinite(soonest)) return;
        var lead = String(state.leadId), category = state.cat;
        var generation = mediaRefreshGeneration;
        // Renew one minute before the earliest link expires; never poll continuously.
        var delay = Math.max(15000, Math.min(540000, (soonest - Date.now()/1000 - 60)*1000));
        mediaRefreshTimer = setTimeout(function(){
          mediaRefreshTimer = null;
          if (generation !== mediaRefreshGeneration || !state.production || state.historyView || state.trashView ||
              state.davView || isCallsCat() || String(state.leadId) !== lead || state.cat !== category || mediaRefreshBusy) return;
          mediaRefreshBusy = true;
          // Keep existing cards visible on a network error. No WebDAV writes.
          amoRequest(listUrl()).then(function(items){
            if (generation === mediaRefreshGeneration && state.production && !state.historyView && !state.trashView && !state.davView &&
                String(state.leadId) === lead && state.cat === category) renderList(items);
          }).catch(function(){
            // Do not clear the old UI or automatically retry through a broken upstream.
          }).then(function(){ mediaRefreshBusy = false; });
        }, delay);
      }

      function enc(s){ return encodeURIComponent(String(s)); }
      function fmtSize(n){ if (!n && n !== 0) return '—'; var u=['Б','КБ','МБ','ГБ']; var i=0; var x=Number(n) || 0; while(x>=1024 && i<u.length-1){ x/=1024; i++; } return (Math.round(x*10)/10)+' '+u[i]; }
      function fmtDate(str){ if (!str) return ''; var d=new Date(str); if (isNaN(d)) return str; return d.toLocaleString('ru-RU'); }
      function splitName(name) { var dot = name.lastIndexOf('.'); if (dot > 0 && dot < name.length - 1) return { base: name.slice(0, dot), ext: name.slice(dot + 1) }; return { base: name, ext: '' }; }
      function ext(name){ var p = splitName(String(name || '')); return (p.ext || '').toLowerCase(); }
      function isCallsCat(){ return state.cat === 'calls'; }
      function fh09Match(name){ return /^fh09-([a-f0-9]{16})-([a-z][a-z0-9-]{0,23})\.txt$/.exec(String(name||'')); }
      function fh09Active(){ return state.canary && state.cat==='other'; }
      function fh11Name(name){ return /^fh11-([a-f0-9]{32})-([a-z][a-z0-9-]{0,19})\.txt$/.exec(String(name||'')); }
      function fh09Url(route){ return CANARY_API+'/'+route; }

      function listUrl(){ return state.backend.base + '/files?leadId='+enc(state.leadId)+'&category='+enc(state.cat); }
      function callsFeedUrl(){ return state.backend.base + '/calls_feed?leadId='+enc(state.leadId); }
      function mediaKey(category, name){ return category + '\u0000' + String(name); }
      function cacheMedia(category, item){
        if (item && typeof item.name === 'string') {
          mediaLinks[mediaKey(category, item.name)] = item;
        }
      }
      function fileUrl(name, opts){ return fileUrlForCategory(state.cat, name, opts); }
      function fileUrlForCategory(category, name, opts){
        if (!state.canary && !state.production) {
          var legacy = state.backend.base + '/file?leadId='+enc(state.leadId)+'&category='+enc(category)+'&name='+enc(name);
          return legacy + ((opts && opts.download) ? '&download=1' : '');
        }
        var item = mediaLinks[mediaKey(category, name)] || {};
        var u = (opts && opts.download) ? item.downloadUrl : item.url;
        return typeof u === 'string' && u.indexOf(state.backend.base + '/file?') === 0 ? u : '#';
      }
      function thumbUrl(name){
        if (!state.canary && !state.production) return state.backend.base + '/thumb?leadId='+enc(state.leadId)+'&category='+enc(state.cat)+'&name='+enc(name)+'&w=260&h=160';
        var item = mediaLinks[mediaKey(state.cat, name)] || {};
        return typeof item.thumbUrl === 'string' && item.thumbUrl.indexOf(state.backend.base + '/thumb?') === 0 ? item.thumbUrl : '';
      }
      // Only synthetic canary lead uses amoCRM JWT. All other leads stay on
      // the original backend using the existing integration settings.
      function fetchWithDeadline(url,settings,method,feature,corr,eventContext){
        var deadline=url.indexOf('/session/refresh')>=0?15000:(method==='GET'?25000:(url.indexOf('/upload')>=0?180000:60000));
        var controller=typeof AbortController==='function'?new AbortController():null;
        var timer=null,started=Date.now(),timedOut=false;
        if(controller){settings.signal=controller.signal;timer=setTimeout(function(){timedOut=true;controller.abort();},deadline);}
        return fetch(url,settings).then(function(r){
          if(r.status===409 && method==='POST')return r.json().catch(function(){return {};}).then(function(body){
            var code=body&&body.error;
            if(code==='write_busy'||code==='reconciliation_required')throw fhError(code,409,code==='reconciliation_required');
            throw fhError('http_409',409,true);
          });
          if(!r.ok){
            if(r.status===401){
              // Сервер различает «сессию ещё можно продлить» и «сессия мертва».
              // Без чтения тела ответа клиент не мог выбрать стратегию и показывал
              // пользователю «Ошибка подключения к бэкенду» при живом бэкенде.
              return r.json().catch(function(){return {};}).then(function(body){
                var code=(body&&typeof body.error==='string')?body.error:'http_401';
                throw fhError(code,r.status,method!=='GET');
              });
            }
            if(r.status===429){
              return r.json().catch(function(){return {};}).then(function(body){
                var code=body&&typeof body.error==='string'&&/^[a-z0-9_]{1,64}$/.test(body.error)
                  ?body.error:'http_429';
                var failure=fhError(code,429,method!=='GET');
                if(r.headers){
                  var retry=r.headers.get('Retry-After');
                  if(/^[0-9]{1,3}$/.test(retry||''))
                    failure.retryAfterMs=Math.min(60000,Math.max(500,1000*Number(retry)));
                }
                throw failure;
              });
            }
            var failure=fhError('http_'+r.status,r.status,method!=='GET');
            throw failure;
          }
          return r.json().catch(function(){throw fhError('response_invalid',r.status,method!=='GET');});
        }).catch(function(err){
          var normalized=timedOut?fhError('request_timeout',0,method!=='GET'):
            (err&&err.code?err:fhError('network_error',0,method!=='GET'));
          telemetry(timedOut?'request_timeout':'request_failed',
            {feature:feature,http_status:normalized.status,corr_id:corr,duration_ms:Date.now()-started,
             error_code:normalized.code||'request_failed',context:eventContext});
          throw normalized;
        }).then(function(value){if(timer!==null)clearTimeout(timer);return value;},
                function(err){if(timer!==null)clearTimeout(timer);throw err;});
      }
      // Признак операции для телеметрии берётся из endpoint'а. Раньше все POST-запросы
      // помечались как 'upload', и по телеметрии нельзя было отличить переименование
      // от корзины — диагностика операций вводила в заблуждение.
      function telemetryFeature(method,url){
        var ep=String(url||'').split('?')[0];
        if(ep.indexOf('/rename')>=0)return 'rename';
        if(ep.indexOf('/restore')>=0)return 'restore';
        if(ep.indexOf('/trash')>=0)return 'trash';
        if(ep.indexOf('/upload')>=0)return 'upload';
        if(ep.indexOf('/calls_feed')>=0)return 'calls';
        if(String(method||'GET').toUpperCase()==='GET')return 'files';
        return 'upload';
      }
      function amoRequest(url, opts){
        opts=opts||{};
        var method=String(opts.method||'GET').toUpperCase();
        var feature=telemetryFeature(method,url);
        var corr=newCorrId();
        var eventContext=opts.telemetryContext||captureTelemetryContext();
        if (!state.canary && !state.production) {
          var headers={'X-API-KEY':state.backend.key};
          var f={method:method,headers:headers};
          if(Object.prototype.hasOwnProperty.call(opts,'data')){
            f.body=opts.data;if(opts.contentType==='application/json')headers['Content-Type']='application/json';
          }
          return fetchWithDeadline(url,f,method,feature,corr,eventContext);
        }
        if(url.indexOf(CANARY_API+'/')!==0){
          telemetry('request_failed',{feature:feature,error_code:'route_invalid',context:eventContext});
          return Promise.reject(fhError('route_invalid'));
        }
        var endpoint=url.substring(CANARY_API.length).split('?')[0];
        var canWrite=method==='POST'&&['/upload','/rename','/trash','/restore','/fh11'].indexOf(endpoint)>=0;
        if(method!=='GET'&&!canWrite){
          telemetry('request_failed',{feature:feature,error_code:'route_invalid',context:eventContext});
          return Promise.reject(fhError('route_invalid'));
        }
        if(!contextIsCurrent(state.contextKey)){
          telemetry('context_missing',{feature:'auth',error_code:'context_missing',context:eventContext});return Promise.reject(fhError('context_missing'));
        }
        var context=state.contextKey;
        var usedToken='';
        return ensureCanarySession(eventContext).then(function(token){
          if(!contextIsCurrent(context))throw fhError('context_missing');
          var rateRetryUsed=false;
          function send(sessToken){
            usedToken=sessToken;
            var headers={'X-FH-Session':sessToken};
            if(canWrite&&opts.contentType==='application/json')headers['Content-Type']='application/json';
            var settings={method:method,mode:'cors',credentials:'omit',cache:'no-store',headers:headers};
            if(canWrite)settings.body=opts.data;
            return fetchWithDeadline(url,settings,method,feature,corr,eventContext);
          }
          return send(token).catch(function(err){
            // Сессию ещё можно продлить: продлеваем и повторяем запрос ОДИН раз.
            // Раньше виджет просто отказывался, и пользователь видел
            // «Ошибка подключения к бэкенду» при полностью живом бэкенде.
            if(err&&err.code==='session_refreshable')
              return refreshCanarySession(context,eventContext).then(send);
            // Edge rate limiting is retryable only for idempotent reads. Writes are
            // never repeated automatically because the first request may have landed.
            var rateLimited=err&&(Number(err.status)===429||err.code==='gateway_rate_limited');
            if(method==='GET'&&!rateRetryUsed&&rateLimited){
              rateRetryUsed=true;
              var wait=Math.min(5000,Math.max(500,Number(err.retryAfterMs)||1000));
              return new Promise(function(resolve){setTimeout(resolve,wait);}).then(function(){
                return ensureCanarySession(eventContext).then(send);
              });
            }
            throw err;
          });
        }).catch(function(err){
          // Токен удаляется только когда сессия действительно мертва. Для
          // session_refreshable токен ещё пригоден для продления, терять его нельзя.
          var code=err&&err.code;
          var hard=(code==='session_invalid'||code==='session_absolute_expired'||code==='session_revoked'||
                   code==='session_expired'||code==='amo_session_denied'||code==='session_refresh_failed');
          if(err&&err.status===401&&hard){
            var entry=authStateFor(context);entry.token=null;entry.expires=0;entry.absoluteExpires=0;
            if(context===state.contextKey)clearRefreshTimer();
            // Общую запись стираем только если в ней всё ещё тот токен, который
            // отверг сервер: иначе гонка удалила бы уже продлённую сессию.
            clearStoredSession(context,usedToken);
          }
          if(['session_reauth_blocked','session_expired','session_absolute_expired','session_revoked',
              'amo_session_denied','session_invalid'].indexOf(code)>=0&&typeof schedulePeerRecovery==='function')
            schedulePeerRecovery(2000);
          throw err;
        });
      }
      function clearRefreshTimer(){
        if(refreshTimer!==null){clearTimeout(refreshTimer);refreshTimer=null;}
      }
      /**
       * Стирание общей (на вкладку) записи сессии.
       *
       * Запись стирается ТОЛЬКО если она всё ещё содержит тот токен, который
       * отверг сервер. Общая запись — общая для всех карточек вкладки: соседняя
       * карточка могла уже продлить сессию и записать НОВЫЙ токен, пока летел
       * отказ по старому. Без проверки гонка стирала живую сессию, и все
       * остальные карточки получали «нет действующей сессии».
       * @param {string} context контекст (аккаунт+пользователь)
       * @param {string} failedToken токен, который отверг сервер (пусто — стирать безусловно)
       * @return {boolean} true — запись стёрта
       */
      function clearStoredSession(context,failedToken){
        try{
          var raw=sessionStorage.getItem(FH_SESSION_STORAGE_PREFIX+context);
          if(!raw)return true;
          var row=null;try{row=JSON.parse(raw);}catch(e){row=null;}
          var stored=(row&&typeof row.token==='string')?row.token:'';
          // В записи уже другой токен — её не трогаем.
          if(stored&&failedToken&&stored!==failedToken)return false;
          sessionStorage.removeItem(FH_SESSION_STORAGE_PREFIX+context);
          return true;
        }catch(e){return false;}
      }
      function clearCanarySession(failedToken){
        clearRefreshTimer();
        if(!state.contextKey)return;
        var entry=authStateFor(state.contextKey);
        entry.token=null;entry.expires=0;entry.absoluteExpires=0;
        // failedToken задан — стираем только ту запись, где лежит именно он.
        clearStoredSession(state.contextKey,failedToken||'');
        // JWT attempt marker is deliberately NEVER removed by this function.
      }
      // Смещение часов браузера относительно сервера. Сервер отдаёт serverTime в
      // ответах сессии; без этого клиент считал бы верхнюю границу абсолютного
      // срока по своим часам и отвергал бы СВЕЖУЮ сессию, если его часы отстают
      // больше чем на десять секунд (тогда JWT amoCRM уже израсходован, а виджет
      // мёртв до повторного входа в amoCRM).
      var serverClockOffsetMs=null;
      function noteServerTime(serverTime){
        var n=Number(serverTime);
        if(!isFinite(n)||n<=0)return;
        var offset=n*1000-Date.now();
        // Часы браузера не могут врать больше часа — иначе это не часы, а мусор.
        if(!isFinite(offset)||Math.abs(offset)>3600000)return;
        serverClockOffsetMs=offset;
      }
      function serverNowS(){
        return Math.floor((Date.now()+(serverClockOffsetMs===null?0:serverClockOffsetMs))/1000);
      }
      function saveCanarySession(context, token, expires, absoluteExpires, options){
        var now=Math.floor(Date.now()/1000);
        // Верхняя граница абсолютного срока — по СЕРВЕРНЫМ часам: сервер гарантирует
        // absolute_expires <= server_now + 1800. Если серверное время ещё неизвестно
        // (первый ответ не дошёл), допускаем 60 секунд расхождения по клиентским часам.
        var maxAbsolute=(serverClockOffsetMs===null?now+1860:serverNowS()+1810);
        if(!/^[a-f0-9]{64}$/.test(token)||!Number.isInteger(expires)||expires<=now||
           !Number.isInteger(absoluteExpires)||absoluteExpires<expires||absoluteExpires>maxAbsolute)
          throw fhError('session_invalid');
        if(!contextIsCurrent(context))
          throw fhError('context_missing');
        var entry=authStateFor(context);
        if(entry.token&&entry.absoluteExpires>0&&absoluteExpires>entry.absoluteExpires&&
           (entry.token===token||entry.expires>now))
          throw fhError('session_invalid'); // Never extend the verified absolute lifetime.
        // Store atomically as a single JSON value; sessionStorage is tab-scoped (not localStorage).
        var row={context:context,token:token,expires:expires,absoluteExpires:absoluteExpires};
        try{sessionStorage.setItem(FH_SESSION_STORAGE_PREFIX+context,JSON.stringify(row));}
        catch(e){throw fhError('auth_storage_unavailable');}
        entry.token=token;entry.expires=expires;entry.absoluteExpires=absoluteExpires;
        if(typeof clearPeerRecovery==='function')clearPeerRecovery();
        if(typeof peerRecoveryDelay!=='undefined')peerRecoveryDelay=FH_PEER_RECOVERY_MIN_MS;
        if(!(options&&options.announce===false)&&typeof announcePeerSession==='function')
          announcePeerSession(context);
        scheduleCanaryRefresh(context);
        // Persistent events may not be loaded into this instance yet. Always ask
        // flushTelemetry to read the queue after a session becomes available.
        if(typeof scheduleTelemetryFlush==='function')scheduleTelemetryFlush(0);
        else if(telemetryEvents.length&&!telemetryTimer)telemetryTimer=setTimeout(flushTelemetry,0);
        return token;
      }
      function announcePeerSession(context){
        if(destroyed||!context||context!==state.contextKey||!ensurePeerChannel(context))return;
        try{peerChannel.postMessage({kind:'available'});}catch(e){}
      }
      function knownCanarySession(context){
        var s=authStateFor(context);
        try{
          var saved=JSON.parse(sessionStorage.getItem(FH_SESSION_STORAGE_PREFIX+context)||'null');
          if(saved&&saved.context===context&&/^[a-f0-9]{64}$/.test(saved.token)&&
            Number.isInteger(saved.expires)&&Number.isInteger(saved.absoluteExpires)&&
            saved.expires<=saved.absoluteExpires){
            // Some other instance may have refreshed the shared entry: prefer the newer deadline.
            if(!s.token||saved.expires>s.expires){
              s.token=saved.token;s.expires=saved.expires;s.absoluteExpires=saved.absoluteExpires;
            }
          }
        }catch(e){} // A bad cache is never trusted and never causes a new JWT attempt.
        return s;
      }
      function cachedCanarySession(){
        if(!contextIsCurrent(state.contextKey))return null;
        var s=knownCanarySession(state.contextKey);
        return s.token&&s.expires>Math.floor(Date.now()/1000)?s.token:null;
      }
      function scheduleCanaryRefresh(context){
        clearRefreshTimer();
        if(destroyed||state.contextKey!==context)return;
        var entry=authStateFor(context),now=Date.now(),nowS=Math.floor(now/1000);
        if(!entry.token||!entry.absoluteExpires||entry.absoluteExpires<=nowS)return;
        // Never spin on 404 / unsupported endpoint or on rate-limited requests.
        if(entry.retryNotBefore===Infinity)return;
        var wait;
        if(entry.expires<=nowS){
          // idle-дедлайн истёк, абсолютный срок ещё жив: сессия продлевается, но
          // сервер сейчас просит подождать (retryNotBefore). Ставим таймер ровно на
          // него — иначе сессия осталась бы мёртвой до следующего действия пользователя.
          if(!(entry.retryNotBefore>now))return;
          wait=Math.max(1000,entry.retryNotBefore-now);
        }else{
          if(entry.expires>=entry.absoluteExpires)return;
          var untilRefresh=Math.max(1000,(entry.expires-nowS-FH_REFRESH_EARLY_S)*1000);
          wait=Math.max(untilRefresh,Math.max(0,entry.retryNotBefore-now));
        }
        refreshTimer=setTimeout(function(){
          refreshTimer=null;
          if(destroyed||state.contextKey!==context)return;
          ensureCanarySession().catch(function(){
            // File operations receive meaningful errors; never start new /session automatically.
          });
        },Math.max(1000,wait));
      }
      function refreshCanarySession(context,eventContext){
        var shared=authStateFor(context),nowS=Math.floor(Date.now()/1000);
        if(!shared.token)
          return Promise.reject(fhError('session_expired',401));
        if(shared.absoluteExpires<=nowS)
          return Promise.reject(fhError('session_absolute_expired',401));
        // idle-дедлайн мог истечь (фоновая вкладка, троттлинг таймера), но
        // абсолютный срок ещё жив — сервер такую сессию продлевает.
        if(shared.expires<=nowS&&shared.absoluteExpires<=nowS)
          return Promise.reject(fhError('session_expired',401));
        if(shared.refreshInflight)return shared.refreshInflight;
        // Сервер просит подождать до retryNotBefore. Отдавать ТОТ ЖЕ токен нельзя,
        // если idle-дедлайн уже истёк: такой запрос уйдёт заведомо мёртвым и
        // закончится «сессия устарела, но может быть продлена» без кнопки
        // восстановления. Вместо этого ждём нужного момента и повторяем продление.
        if(Date.now()<shared.retryNotBefore){
          if(shared.expires<=nowS){
            scheduleCanaryRefresh(context);
            return Promise.reject(fhError('session_refresh_throttled',429));
          }
          return Promise.resolve(shared.token);
        }
        shared.refreshInflight=underAuthLock(context,function(){
          // Lock also covers an actual network response; cross-instance single flight.
          var now=Math.floor(Date.now()/1000),live=knownCanarySession(context);
          if(!live.token)throw fhError('session_expired',401);
          if(live.absoluteExpires<=now)throw fhError('session_absolute_expired',401);
          if(live.expires>now+FH_REFRESH_EARLY_S)return live.token;
          if(Date.now()<live.retryNotBefore){
            // Тот же случай, что и выше: мёртвый по idle токен отдавать нельзя.
            if(live.expires<=now){
              scheduleCanaryRefresh(context);
              throw fhError('session_refresh_throttled',429);
            }
            return live.token;
          }
          return requestLivePeerSession(context).then(function(peerToken){
          now=Math.floor(Date.now()/1000);
          if(peerToken&&live.expires>now+FH_REFRESH_EARLY_S)return peerToken;
          if(live.expires<=now&&live.absoluteExpires<=now)throw fhError('session_absolute_expired',401);
          var oldToken=live.token;
          var corr=newCorrId();
          return fetchWithDeadline(CANARY_API+'/session/refresh',{
            method:'POST',mode:'cors',credentials:'omit',cache:'no-store',
            headers:{'X-FH-Session':oldToken,'Content-Type':'application/json','X-Correlation-ID':corr},
            body:'{}'
          },'POST','auth',corr,eventContext||captureTelemetryContext(context)).then(function(res){
            noteServerTime(res&&res.serverTime);
            if(!res||res.sessionToken!==oldToken||!Number.isInteger(res.expires)||
               !Number.isInteger(res.absoluteExpires)||res.expires>res.absoluteExpires||
               (live.absoluteExpires>0&&res.absoluteExpires!==live.absoluteExpires))
               throw fhError('session_invalid');
            live.retryNotBefore=0;
            return saveCanarySession(context,oldToken,res.expires,res.absoluteExpires);
          },function(err){
            var status=Number(err&&err.status)||0;
            var nowErr=Math.floor(Date.now()/1000);
            if(status===429){live.retryNotBefore=Date.now()+(err.retryAfterMs||FH_REFRESH_RETRY_MS);}
            else if(status===404||status===405){live.retryNotBefore=Infinity;}
            else if(status===503||status===502||status===0){live.retryNotBefore=Date.now()+15000;}
            if([401,403].indexOf(status)>=0){
              // Do not call /session with a consumed JWT. Existing operations stop safely.
              // Стираем общую запись только если в ней всё ещё этот токен.
              clearCanarySession(oldToken);
              throw fhError(status===401?'session_expired':'session_refresh_failed',status);
            }
            if(live.expires>nowErr){
              scheduleCanaryRefresh(context);
              return live.token; // Retain only a *still valid* session; do not discard it for 429/503.
            }
            // Токен по idle уже мёртв, но абсолютный срок ещё жив: сессию можно
            // продлить позже. Убивать её нельзя — ждём retryNotBefore и повторяем.
            if(live.absoluteExpires>nowErr&&live.retryNotBefore>Date.now()){
              scheduleCanaryRefresh(context);
              throw fhError('session_refresh_throttled',429);
            }
            throw fhError('session_refresh_failed',status);
          });
          });
        }).then(function(result){shared.refreshInflight=null;return result;},
                function(err){shared.refreshInflight=null;throw err;});
        return shared.refreshInflight;
      }
      function ensureCanarySession(eventContext){
        eventContext=eventContext||captureTelemetryContext();
        var context=state.contextKey;
        if(!contextIsCurrent(context))return Promise.reject(fhError('context_missing'));
        ensurePeerChannel(context);
        var shared=knownCanarySession(context),now=Math.floor(Date.now()/1000);
        if(shared.token){
          if(shared.expires<=now){
            if(shared.absoluteExpires>0&&shared.absoluteExpires<=now)
              return requestLivePeerSession(context).then(function(token){
                if(token)return token;
                if(typeof schedulePeerRecovery==='function')schedulePeerRecovery(5000);
                throw fhError('session_absolute_expired',401);
              });
            // idle истёк, но абсолютный срок жив: продлеваем сессию. Раньше здесь
            // был отказ, и пользователь видел «Ошибку подключения к бэкенду»,
            // хотя продлить сессию было можно.
            return refreshCanarySession(context,eventContext).catch(function(err){
              return requestLivePeerSession(context).then(function(token){
                if(token)return token;
                throw err;
              });
            });
          }
          if(shared.absoluteExpires>now&&shared.expires<shared.absoluteExpires&&shared.expires<=now+FH_REFRESH_EARLY_S)
            return refreshCanarySession(context,eventContext);
          clearPeerRecovery();
          return Promise.resolve(shared.token);
        }
        if(shared.inflight)return shared.inflight;
        shared.inflight=underAuthLock(context,function(){
          var valid=cachedCanarySession();if(valid)return valid;
          return requestLivePeerSession(context).then(function(peerToken){
            if(peerToken)return peerToken;
            // Одноразовый JWT amoCRM уже израсходован, и повторно отправить его
            // нельзя: сервер откажет (jwt_replayed). Но живая сессия может быть
            // в ДРУГОЙ вкладке amoCRM — sessionStorage живёт на вкладку, и
            // вкладки-донора может не быть. Раньше виджет в этом случае просто
            // отказывался до следующего абсолютного срока (до 30 минут), и
            // пользователь всё это время видел «Ошибку подключения к бэкенду».
            //
            // amoCRM выдаёт JWT заново при каждом обращении к $authorizedAjax
            // (в проде подтверждено: 200+ успешных выдач без единого replay), так
            // что делаем ОДНУ новую попытку. Если JWT окажется тем же самым,
            // сервер честно откажет — тогда останется прежнее сообщение с
            // кнопкой восстановления.
            var blocked=knownAttempt(context);
            if(blocked&&!authRetryAllowed(context)){
              if(typeof schedulePeerRecovery==='function')schedulePeerRecovery(5000);
              telemetry('session_reauth_blocked',{feature:'auth',error_code:'session_reauth_blocked',context:eventContext});
              throw fhError('session_reauth_blocked');
            }
            var attemptId=reserveAttempt(context,!blocked);
            if(!attemptId){
              if(typeof schedulePeerRecovery==='function')schedulePeerRecovery(5000);
              telemetry('session_reauth_blocked',{feature:'auth',error_code:'session_reauth_blocked',context:eventContext});
              throw fhError('session_reauth_blocked');
            }
            telemetry('session_attempt',{feature:'auth',context:eventContext});
            var started=Date.now();
            return new Promise(function(resolve,reject){
              if(!self.$authorizedAjax){
                if(typeof recordAttemptFailure==='function')recordAttemptFailure(context,attemptId,'session_unavailable',0);
                reject(fhError('session_unavailable'));return;
              }
              try{
                self.$authorizedAjax({url:CANARY_API+'/session',type:'POST',dataType:'json',timeout:20000})
                  .done(function(res){
                    try{
                      if(!res||!Number.isInteger(res.absoluteExpires))
                        throw fhError('session_invalid');
                      noteServerTime(res.serverTime);
                      var token=saveCanarySession(context,res.sessionToken,res.expires,res.absoluteExpires);
                      recordAttemptSession(context,attemptId,res.absoluteExpires);
                      telemetry('session_issued',{feature:'auth',duration_ms:Date.now()-started,context:eventContext});
                      resolve(token);
                    }catch(e){reject(e);}
                  })
                  .fail(function(xhr,textStatus){
                    var status=Number(xhr&&xhr.status)||0;
                    var errorCode=status===401?'amo_session_denied':
                      textStatus==='timeout'?'request_timeout':status===0?'network_error':'http_'+status;
                    telemetry('session_failed',{feature:'auth',http_status:status,duration_ms:Date.now()-started,
                      error_code:errorCode,context:eventContext});
                    if(typeof recordAttemptFailure==='function')recordAttemptFailure(context,attemptId,errorCode,status);
                    // Повторная попытка (сессии не было ни у нас, ни у донора) с
                    // тем же самым JWT: сервер честно отказал. Пользователю нужен
                    // тот же понятный текст с кнопкой восстановления, а не
                    // «amoCRM не принял авторизацию».
                    if(blocked&&status===401){
                      if(typeof schedulePeerRecovery==='function')schedulePeerRecovery(5000);
                      telemetry('session_reauth_blocked',{feature:'auth',error_code:'session_reauth_blocked',context:eventContext});
                      reject(fhError('session_reauth_blocked',401));
                      return;
                    }
                    reject(fhError('session_unavailable',status));
                  });
              }catch(e){
                if(typeof recordAttemptFailure==='function')recordAttemptFailure(context,attemptId,'session_unavailable',0);
                reject(fhError('session_unavailable'));
              }
            });
          });
        }).then(function(value){shared.inflight=null;return value;},
                function(err){shared.inflight=null;throw err;});
        return shared.inflight;
      }

      function isImageItem(it){
        var name=String((it && it.name)||'').toLowerCase();
        var mime=String((it && it.contentType)||'').toLowerCase().split(';')[0];
        // HEIC/HEIF/TIFF/SVG are not consistently supported (or need special handling).
        if (/\.(heic|heif|tif|tiff|svg)$/i.test(name) || /^(image\/(heic|heif|tiff|svg\+xml))$/.test(mime)) return false;
        return /\.(jpe?g|png|webp|gif|bmp|avif)$/i.test(name) ||
               /^(image\/(jpeg|png|webp|gif|bmp|x-ms-bmp|avif))$/.test(mime);
      }
      // The server thumbnailer supports JPEG, PNG, WebP and GIF only. BMP/AVIF
      // can still be opened in the image viewer, but asking for a thumbnail of
      // them produced a false "thumbnail failed" event on every list render.
      function isServerThumbItem(it){
        var name=String((it && it.name)||'').toLowerCase();
        var mime=String((it && it.contentType)||'').toLowerCase().split(';')[0];
        return /\.(jpe?g|png|webp|gif)$/i.test(name) ||
               /^(image\/(jpeg|pjpeg|png|webp|gif))$/.test(mime);
      }
      function isPdfItem(it){ return /pdf/i.test(String((it && it.contentType)||'')) || /\.pdf$/i.test((it && it.name)||''); }
      function isAudioItem(it){ return /^audio\//i.test((it && it.contentType)||'') || /\.(mp3|wav|ogg|m4a|amr|aac|flac|opus)$/i.test((it && it.name)||''); }
      function isVideoItem(it){ return /^video\//i.test((it && it.contentType)||'') || /\.(mp4|m4v|webm|mov)$/i.test((it && it.name)||''); }


      function html(){
        return ''+
        '<div id="'+IDS.root+'" class="czfh-filehub-container">'+
          '<div class="czfh-header">'+
            '<div class="czfh-title">'+t('tab_title','Файлы пациента')+'</div>'+
            '<div class="czfh-tabs" id="'+IDS.tabs+'"></div>'+
            '<button id="'+IDS.btn+'" class="czfh-btn">📤 '+t('upload_button','Загрузить файлы')+'</button>'+ 
            '<button id="'+IDS.trashBtn+'" class="czfh-btn czfh-btn--trash" style="display:none" type="button">🗑 Корзина</button>'+
            '<button id="'+IDS.historyBtn+'" class="czfh-btn czfh-btn--history" style="display:none" type="button">📋 История</button>'+ 
            '<button id="'+IDS.davBtn+'" class="czfh-btn czfh-btn--trash" style="display:none" type="button">☁ Mail.ru (тест)</button>'+
          '</div>'+
          '<div class="czfh-body">'+
            '<div id="'+IDS.drop+'" class="czfh-drop">'+t('drop_area', 'Перетащите файлы сюда')+'</div>'+
            '<div id="'+IDS.progress+'" class="czfh-progress"><div id="'+IDS.fill+'" class="fill"></div></div>'+
            '<div id="czfh_upload_status" class="czfh-upload-status" aria-live="polite"></div>'+ 
          '<div id="czfh_queue_items" class="czfh-queue-items" aria-live="polite"></div>'+ 
          '<div id="czfh_queue_actions" class="czfh-queue-actions" style="display:none">'+
            '<button type="button" id="czfh_queue_review">Проверить список файлов</button> '+
            '<button type="button" id="czfh_queue_clear">Очистить остановленную очередь</button>'+
          '</div>'+ 
            '<div id="'+IDS.list+'" class="czfh-files"></div>'+
          '</div>'+
        '</div>'+
        '<div id="'+IDS.modal+'" class="czfh-modal" role="dialog" aria-modal="true" aria-label="Просмотр файла" tabindex="-1">'+
          '<div class="czfh-modal__inner">'+
            '<div class="czfh-modal__bar"><div class="czfh-modal__title" id="'+IDS.modalTitle+'">Просмотр</div><button type="button" class="czfh-modal__close" id="'+IDS.modalClose+'" aria-label="Закрыть просмотр">✕</button></div>'+
            '<div class="czfh-modal__body"><iframe id="'+IDS.modalFrame+'" class="czfh-modal__iframe"></iframe></div>'+
          '</div>'+
        '</div>';
      }

      function refreshCurrentCategory(){
        viewGeneration++;
        listRequestGeneration++;
        stopMediaRefresh();
        updateModeUI();
        return state.historyView ? loadHistory() : (isCallsCat() ? loadCallsFeed() : (fh09Active() && state.davView ? loadDav() : ((fh09Active() || state.production) && state.trashView ? loadTrash() : loadList())));
      }

      function drawTabs(){
        var $tabs = $('#'+IDS.tabs); if (!$tabs.length) return;
        $tabs.empty();
        CATS.forEach(function(c){
          $('<div class="czfh-tab"></div>')
            .toggleClass('active', state.cat === c.key)
            .attr('data-cat', c.key)
            .text(t(c.titleKey, c.title))
            .appendTo($tabs);
        });
        $tabs.find('.czfh-tab').off('click').on('click', function(){
          var cat = $(this).data('cat');
          state.cat = cat; state.trashView=false; state.historyView=false; state.davView=false;
          $tabs.find('.czfh-tab').removeClass('active');
          $(this).addClass('active');
          refreshCurrentCategory();
        });
      }

      function updateModeUI(){
        var canaryOther=fh09Active();
        var readOnly=isCallsCat() || state.canary;
        $('#'+IDS.btn).toggle(!isCallsCat() && (!state.canary || (canaryOther && !state.davView)))
          .text(canaryOther ? '🧪 Создать тестовый TXT' : '📤 '+t('upload_button','Загрузить файлы'));
        $('#'+IDS.trashBtn).toggle(!state.historyView && ((canaryOther && !state.davView) || (state.production && !isCallsCat()))).text(state.trashView ? '← К файлам' : '🗑 Корзина');
        $('#'+IDS.historyBtn).toggle(state.production).text(state.historyView?'← К файлам':'📋 История');
        $('#'+IDS.btn).toggle(!state.historyView && !isCallsCat() && (!state.canary || (canaryOther && !state.davView)));
        $('#'+IDS.davBtn).toggle(canaryOther).text(state.davView ? '← Локальные тесты' : '☁ Mail.ru (тест)');
        $('#'+IDS.drop).toggle(!readOnly && !state.historyView && !state.trashView);
        if (readOnly) $('#'+IDS.progress).hide();
      }

      function isSessionRecoveryCode(code){
        return ['session_reauth_blocked','session_expired','session_absolute_expired','auth_lock_timeout',
          'session_refresh_failed','session_refreshable','session_refresh_throttled','session_revoked',
          'session_invalid','amo_session_denied'].indexOf(code)>=0;
      }

      function renderError(message,code){
        var $host=$('#'+IDS.list).empty();
        $host.append($('<p class="czfh-error"></p>').text('❌ ' + message));
        authErrorVisible=isSessionRecoveryCode(code);
        if(authErrorVisible&&typeof schedulePeerRecovery==='function')schedulePeerRecovery(1000);
        if(!authErrorVisible)return;
        $host.append($('<p class="czfh-error"></p>').text(
          'Если другая вкладка FileHub открыта, обновите эту карточку. Если нет — повторно войдите в amoCRM для получения нового JWT.'));
        $('<button type="button" class="czfh-auth-retry"></button>')
          .text('Проверить подключение после нового входа в amoCRM')
          .on('click',function(){
            if(!contextIsCurrent(state.contextKey))return;
            if(!window.confirm||!window.confirm('Вы уже завершили повторный вход в amoCRM? Старый JWT не будет принят сервером. Повторная попытка выполняется только по вашему нажатию.'))return;
            var ctx=state.contextKey, retryKey='czfh-manual-reauth-v1:'+ctx;
            try{
              var last=Number(window.localStorage.getItem(retryKey)||0);
              if(last>0&&Date.now()-last<60000){alert('Повторите попытку не раньше чем через минуту.');return;}
              window.localStorage.setItem(retryKey,String(Date.now()));
              window.localStorage.removeItem(storageKeyFor(ctx));
              var entry=authStateFor(ctx);
              entry.retryNotBefore=0;entry.inflight=null;entry.refreshInflight=null;
              clearCanarySession();
              refreshCurrentCategory();
            }catch(e){renderError(fhError('auth_storage_unavailable').message);}
          }).appendTo($host);
      }

      function openPreview(name, category){
        category = category || state.cat;
        var eventContext=captureTelemetryContext();
        eventContext.category=category;
        if (activePreviewCleanup) { activePreviewCleanup(); activePreviewCleanup=null; }
        var $modal=$('#'+IDS.modal);
        var $body=$modal.find('.czfh-modal__body').empty();
        $('#'+IDS.modalTitle).text(name);
        var url=fileUrlForCategory(category,name);
        if (!url || url==='#') {
          $body.append($('<p class="czfh-view-fallback"></p>').text('Ссылка на файл недоступна или истекла. Обновите список файлов.'));
          $modal.css('display','flex').trigger('focus'); return;
        }
        var it=mediaLinks[mediaKey(category,name)] || {name:name};
        var $stage=$('<div class="czfh-view-stage"></div>');
        function toolbarBtn($tools, label, title, fn){
          return $('<button type="button" class="czfh-view-tool"></button>')
            .text(label).attr('title',title).attr('aria-label',title).on('click',fn).appendTo($tools);
        }
        function resourceLink($tools, title, href, isDownload){
          if (!href || href==='#') return;
          var $a=$('<a class="czfh-view-tool czfh-view-tool--link"></a>')
            .attr('href',href).attr('target','_blank').attr('rel','noopener noreferrer').text(title);
          if (isDownload) $a.attr('download','');
          $tools.append($a);
        }
        var $tools=$('<div class="czfh-view-tools"></div>');
        var download=fileUrlForCategory(category,name,{download:true});
        if (isImageItem(it)) {
          var $img=$('<img class="czfh-view-image" alt="" draggable="false">');
          var zoom=1, rotation=0, panX=0, panY=0, lastPointer=null;
          var naturalW=0,naturalH=0,baseFit=1;
          var stageEl=$stage[0];
          function metrics(){
            var sw=stageEl.clientWidth, sh=stageEl.clientHeight;
            var rw=rotation%180===0?naturalW:naturalH;
            var rh=rotation%180===0?naturalH:naturalW;
            if (!sw || !sh || !rw || !rh) return null;
            baseFit=Math.min(1,sw/rw,sh/rh);
            var w=rw*baseFit*zoom, h=rh*baseFit*zoom;
            return {sw:sw,sh:sh,w:w,h:h};
          }
          function update(){
            var m=metrics(); if (!m) return;
            panX=Math.max(-Math.max(0,(m.w-m.sw)/2),Math.min(Math.max(0,(m.w-m.sw)/2),panX));
            panY=Math.max(-Math.max(0,(m.h-m.sh)/2),Math.min(Math.max(0,(m.h-m.sh)/2),panY));
            $img.css({width:naturalW+'px',height:naturalH+'px',
              transform:'translate(-50%, -50%) translate('+panX+'px, '+panY+'px) rotate('+rotation+'deg) scale('+(baseFit*zoom)+')'});
            $stage.toggleClass('czfh-view-stage--pan',zoom>1);
            $percent.text(Math.round(zoom*100)+'%');
          }
          function changeZoom(mult){zoom=Math.max(.25,Math.min(8,zoom*mult));update();}
          function fit(){zoom=1;panX=0;panY=0;update();}
          function rotate(n){rotation=(rotation+n+360)%360;panX=0;panY=0;update();}
          var $percent=$('<span class="czfh-view-percent" aria-live="polite">100%</span>');
          toolbarBtn($tools,'−','Уменьшить',function(){changeZoom(1/1.25);});
          $tools.append($percent);
          toolbarBtn($tools,'+','Увеличить',function(){changeZoom(1.25);});
          toolbarBtn($tools,'Вписать','Вписать изображение в экран',fit);
          toolbarBtn($tools,'↶','Повернуть влево на 90°',function(){rotate(-90);});
          toolbarBtn($tools,'↷','Повернуть вправо на 90°',function(){rotate(90);});
          $stage.append($img);
          var onLoad=function(){naturalW=this.naturalWidth;naturalH=this.naturalHeight;fit();};
          $img.on('load',onLoad).on('error',function(){
              telemetry('image_preview_failed',{feature:'preview',error_code:'image_load_failed',context:eventContext});
             $stage.empty().removeClass('czfh-view-stage--pan')
               .append($('<p class="czfh-view-fallback"></p>')
               .text('Не удалось показать изображение: ссылка могла устареть или сервер недоступен.'));
             $('<button type="button" class="czfh-view-tool">Обновить ссылку</button>')
               .on('click',function(){
                 var leadId=String(state.leadId);
                 var api=state.backend.base+'/files?leadId='+enc(leadId)+'&category='+enc(category);
                 var $retry=$(this).prop('disabled',true).text('Обновляем…');
                 amoRequest(api).then(function(items){
                   if(String(state.leadId)!==leadId || !Array.isArray(items)) return;
                   var found=items.find(function(entry){return entry && entry.name===name;});
                   if(!found){$retry.prop('disabled',false).text('Файл не найден');return;}
                   cacheMedia(category,found);
                   openPreview(name,category);
                 }).catch(function(){ $retry.prop('disabled',false).text('Повторить обновление'); });
               }).appendTo($stage);
           });
           // Attach handlers BEFORE src: cached 403/502 can fail immediately.
           $img.attr('src',url);
          $stage.on('pointerdown',function(e){
            if (zoom<=1 || !naturalW) return;
            var ev=e.originalEvent;
            lastPointer={id:ev.pointerId,x:ev.clientX,y:ev.clientY};
            if(stageEl.setPointerCapture)stageEl.setPointerCapture(ev.pointerId);
          }).on('pointermove',function(e){
            if (!lastPointer || e.originalEvent.pointerId!==lastPointer.id) return;
            var ev=e.originalEvent;
            panX+=ev.clientX-lastPointer.x;panY+=ev.clientY-lastPointer.y;
            lastPointer.x=ev.clientX;lastPointer.y=ev.clientY;update();
          }).on('pointerup pointercancel lostpointercapture',function(){lastPointer=null;})
            .on('dblclick',fit);
          var onWheel=function(ev){if (!naturalW)return;ev.preventDefault();changeZoom(ev.deltaY<0?1.25:1/1.25);};
          stageEl.addEventListener('wheel',onWheel,{passive:false});
          var resizeObserver=null;
          if (window.ResizeObserver){resizeObserver=new ResizeObserver(update);resizeObserver.observe(stageEl);}
          else $(window).on('resize.czfhPreview',update);
          activePreviewCleanup=function(){
            stageEl.removeEventListener('wheel',onWheel);
            if(resizeObserver)resizeObserver.disconnect();
            $(window).off('resize.czfhPreview',update);
            $img.off('load error');$stage.off();
          };
        } else if (isPdfItem(it)) {
          $stage.addClass('czfh-view-stage--pdf');
          var pdfUrl=url.split('#')[0]+'#view=Fit';
          $stage.append($('<iframe class="czfh-view-iframe" title="PDF" referrerpolicy="no-referrer"></iframe>').attr('src',pdfUrl));
          $tools.append($('<span class="czfh-view-hint"></span>').text('PDF: масштаб и поворот — на панели браузера, если поддерживается.'));
          resourceLink($tools,'Открыть PDF',url,false);
        } else if (isAudioItem(it)) {
          var $audio=$('<audio class="czfh-view-audio" controls preload="metadata"></audio>').attr('src',url);
          var $speed=$('<select class="czfh-view-speed" aria-label="Скорость воспроизведения"></select>');
          [1,1.25,1.5,2].forEach(function(x){$speed.append($('<option></option>').val(String(x)).text(String(x)+'×'));});
          $speed.on('change',function(){ $audio.prop('playbackRate',Number($speed.val())||1); });
          $stage.append($audio,$speed);
          $audio.on('error',function(){$stage.append($('<p class="czfh-view-fallback"></p>').text('Этот аудиоформат не поддерживается браузером. Используйте «Скачать».'));});
        } else if (isVideoItem(it)) {
          var $video=$('<video class="czfh-view-video" controls playsinline preload="metadata"></video>').attr('src',url);
          $video.on('error',function(){$stage.append($('<p class="czfh-view-fallback"></p>').text('Видео не воспроизводится: возможно, кодек не поддерживается браузером.'));});
          $stage.append($video);
        } else if (/\.(txt|log|csv)$/i.test(name) || /^text\/(plain|csv)/i.test(it.contentType||'')) {
          var $text=$('<pre class="czfh-view-text"></pre>').text('Загрузка текста…');
          $stage.append($text);
          // Never buffer an arbitrarily large text file in the browser.
          var textController=typeof AbortController==='function'?new AbortController():null;
          var textTimer=textController?setTimeout(function(){textController.abort();},25000):null;
          var textOptions={credentials:'omit',cache:'no-store'};
          if(textController)textOptions.signal=textController.signal;
          fetch(url,textOptions).then(function(r){
            if(!r.ok||!r.body||!r.body.getReader||typeof TextDecoder==='undefined')
              throw fhError('text_preview_unavailable',r.status);
            var reader=r.body.getReader(),decoder=new TextDecoder('utf-8'),chunks='',limit=131072,readBytes=0;
            function readNext(){return reader.read().then(function(part){
              if(part.done)return chunks+decoder.decode();
              readBytes+=part.value.byteLength;
              var room=Math.max(0,limit-(readBytes-part.value.byteLength));
              chunks+=decoder.decode(part.value.subarray(0,room),{stream:true});
              if(readBytes>=limit){reader.cancel().catch(function(){});return chunks+'\n… [предпросмотр ограничен]';}
              return readNext();
            });}
            return readNext();
          }).then(function(content){if($text.closest('#'+IDS.modal).length)$text.text(content);})
            .catch(function(){if($text.closest('#'+IDS.modal).length)$text.text('Не удалось открыть текст. Используйте «Скачать».');})
            .then(function(){if(textTimer!==null)clearTimeout(textTimer);});
        } else {
          $stage.append($('<div class="czfh-view-fallback"></div>')
            .text('Встроенный просмотр этого формата ('+(ext(name)||'неизвестен')+') недоступен. Файл можно открыть в другой программе или скачать.'));
        }
        resourceLink($tools,'Скачать',download && download!=='#'?download:url,true);
        $body.append($tools,$stage);
        $modal.css('display','flex').trigger('focus');
        var keyHandler=function(ev){if(ev.key==='Escape')closePreview();};
        $(document).on('keydown.czfhPreview',keyHandler);
        var existingCleanup=activePreviewCleanup;
        activePreviewCleanup=function(){ $(document).off('keydown.czfhPreview',keyHandler);if(existingCleanup)existingCleanup();};
      }
      function closePreview(){
        if (activePreviewCleanup) {activePreviewCleanup();activePreviewCleanup=null;}
        $('#'+IDS.modal).find('.czfh-modal__body').empty();
        $('#'+IDS.modal).hide();
      }

      function copyText(link){
        if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(link);
        var ta=document.createElement('textarea'); ta.value=link; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); return Promise.resolve();
      }

      function bindCommonCardActions($scope){
        $scope.find('.czfh-del').off('click').on('click', function(e){
          e.preventDefault();
          var $control=$(this);
          if ($control.data('busy')) return;
          var name=$control.data('name');
          var category=$control.data('category') || state.cat;
          var leadId=String(state.leadId);
          if (state.production) {
            if (!confirm('Переместить «'+name+'» в корзину Mail.ru? Файл можно восстановить.')) return;
            $control.data('busy',true).attr('aria-disabled','true').addClass('czfh-is-busy');
            return amoRequest(state.backend.base+'/trash',{method:'POST',contentType:'application/json',
              data:JSON.stringify({leadId:leadId,category:category,name:name})})
              .then(function(){if(String(state.leadId)===leadId && state.cat===category && !state.trashView)loadList();})
              .catch(function(err){alert('Не удалось подтвердить перенос в корзину: '+err.message+'\nПроверьте список и корзину перед повтором.');if(String(state.leadId)===leadId && state.cat===category)refreshCurrentCategory();})
              .then(function(){$control.data('busy',false).attr('aria-disabled','false').removeClass('czfh-is-busy');});
          }
          if (state.canary) {
            if (!fh09Active() || !fh09Match(name)) return;
            if (!confirm('Переместить только синтетический файл в тестовую корзину?')) return;
            $control.data('busy',true).attr('aria-disabled','true').addClass('czfh-is-busy');
            return amoRequest(fh09Url('trash'), {method:'POST',contentType:'application/json',
              data:JSON.stringify({leadId:leadId,category:'other',name:name})})
              .then(loadList).catch(function(err){alert('Тестовая корзина: '+err.message);})
              .then(function(){$control.data('busy',false).attr('aria-disabled','false').removeClass('czfh-is-busy');});
          }
          if(!confirm(t('delete_confirm', 'Удалить файл?'))) return;
          var delUrl=state.backend.base+'/file?leadId='+enc(leadId)+'&category='+enc(category)+'&name='+enc(name);
          amoRequest(delUrl,{method:'DELETE'}).then(loadList)
            .catch(function(err){alert(t('delete_error','Ошибка удаления файла')+': '+err.message);});
        });
        $scope.find('.czfh-rename').off('click').on('click', function(e){
          e.preventDefault();
          var name=$(this).data('name');
          if (state.canary) {
            var match=fh09Match(name);
            if (!fh09Active() || !match) return;
            var slug=prompt('Новое короткое имя тестового TXT (латиница, цифры, дефис)',match[2]);
            if (slug===null) return;
            slug=String(slug).trim().toLowerCase();
            if (!/^[a-z][a-z0-9-]{0,23}$/.test(slug)) {alert('Только латиница, цифры, дефис; до 24 символов');return;}
            var newName='fh09-'+match[1]+'-'+slug+'.txt';
            return amoRequest(fh09Url('rename'), {method:'POST',contentType:'application/json',
              data:JSON.stringify({leadId:String(state.leadId),category:'other',name:name,new_name:newName})})
              .then(loadList).catch(function(err){alert('Переименование: '+err.message);});
          }
          var parts = splitName(name);
          var nn = prompt(t('enter_new_name','Введите новое имя файла'), parts.base);
          if (nn == null) return;
          nn = nn.trim().replace(/[\/\\:*?"<>|]/g,'');
          if (!nn) return;
          var new_name = parts.ext ? (nn + '.' + parts.ext) : nn;
          amoRequest(state.backend.base + '/rename', {
            method:'POST', contentType:'application/json', processData:false,
            data: JSON.stringify({ leadId: String(state.leadId), category: state.cat, name: name, new_name: new_name })
          })
          .then(loadList)
          .catch(function(e){ alert(t('rename_error','Ошибка переименования')+': '+e.message); });
        });
        $scope.find('.czfh-copy').off('click').on('click', function(e){
          e.preventDefault();
          var link = $(this).data('link');
          copyText(link).catch(function(){});
        });
        $scope.find('.czfh-preview').off('click').on('click', function(e){ e.preventDefault(); openPreview($(this).data('name'), $(this).data('category') || state.cat); });
      }

      function appendCommonActions($actions, category, name, options){
        options = options || {};
        var openLink = $('<a target="_blank" rel="noopener noreferrer"></a>').attr('href', fileUrlForCategory(category, name)).text(t('open_button','Открыть'));
        var downloadLink = $('<a download></a>').attr('href', fileUrlForCategory(category, name, {download:true})).text(t('download_button','Скачать'));
        var copyLink = $('<a href="#" class="czfh-copy"></a>').attr('data-link', fileUrlForCategory(category, name)).text(t('copy_link','Ссылка'));
        if (options.preview) { $actions.append($('<a href="#" class="czfh-preview"></a>').attr('data-name', name).attr('data-category', category).text(t('preview_button','Предпросмотр'))); }
        $actions.append(openLink, downloadLink, copyLink);
        if (!options.readOnly) {
          $actions.append($('<a href="#" class="czfh-rename"></a>').attr('data-name', name).text(t('rename_button','Переименовать')));
          $actions.append($('<a href="#" class="czfh-del"></a>').attr('data-name', name).attr('data-category', category).addClass('czfh-destructive-action').text(options.softDelete?'🗑 В корзину':t('delete_button','Удалить')));
        }
      }

       function createThumbNode(it){
         if (isImageItem(it)) {
           // BMP/AVIF are valid preview images, but the server returns no
           // thumbUrl for them. Render a neutral file tile instead of a failure.
           if (!isServerThumbItem(it)) {
             return $('<div class="czfh-thumb czfh-thumb--file"></div>')
               .text((ext(it.name) || 'IMAGE').toUpperCase());
           }
           var thumb=thumbUrl(it.name), category=state.cat, leadId=String(state.leadId);
           var eventContext=captureTelemetryContext();
           eventContext.lead_id=leadId;eventContext.category=category;
           var $frame=$('<div class="czfh-thumb-frame"></div>');
           var $img=$('<img class="czfh-thumb" alt="Миниатюра">').attr('loading','lazy');
           function showFailure(errorCode){
             telemetry('thumb_failed',{feature:'preview',error_code:errorCode,context:eventContext});
             $frame.empty().addClass('czfh-thumb-frame--failed');
             $('<div class="czfh-thumb-empty"></div>').text('Миниатюра недоступна').appendTo($frame);
             $('<button type="button" class="czfh-thumb-retry"></button>').text('Обновить')
               .on('click',function(e){
                 e.preventDefault();e.stopPropagation();
                 if(String(state.leadId)!==leadId||state.cat!==category||state.trashView||state.davView)return;
                 $(this).prop('disabled',true).text('Обновляем…');
                 loadList();
               }).appendTo($frame);
           }
           if(!thumb){showFailure('thumb_url_missing');return $frame;}
           var retried=false,refreshed=false;
           $img.on('error',function(){
             if(!retried){
               retried=true;
               setTimeout(function(){
                 if(!destroyed&&String(state.leadId)===leadId&&state.cat===category&&
                    !state.historyView&&!state.trashView&&!state.davView&&$.contains(document,$frame[0]))
                   $img.removeAttr('src').attr('src',thumb);
               },1000);
               return;
             }
             // Ссылка на миниатюру — подписанная capability с TTL 10 минут: в открытой
             // карточке она успевает истечь. Обновляем список один раз сами, чтобы
             // пользователю не приходилось нажимать «Обновить».
             //
             // Обновление допустимо ТОЛЬКО если подпись действительно истекла:
             // при неисправимой ошибке (404 file_missing, 502 upstream, 503 busy)
             // новый список не поможет, а автоматический цикл loadList -> render ->
             // ошибка -> loadList устраивал бесконечный поток GET /files.
             if(!refreshed){
               refreshed=true;
               var thumbExp=signedExpiry(thumb);
               var linkExpired=thumbExp>0&&thumbExp<=Math.floor(Date.now()/1000);
               if(linkExpired&&!destroyed&&String(state.leadId)===leadId&&state.cat===category&&
                  !state.historyView&&!state.trashView&&!state.davView&&$.contains(document,$frame[0])){
                 requestListAutoRefresh();
                 return;
               }
             }
             showFailure('thumb_image_failed');
           });
           $frame.append($img);$img.attr('src',thumb);
           return $frame;
         }
         if (isPdfItem(it)) return $('<div class="czfh-thumb czfh-thumb--pdf"></div>').text('PDF');
         if (isAudioItem(it)) return $('<div class="czfh-thumb czfh-thumb--file"></div>').text('AUDIO');
         if (isVideoItem(it)) return $('<div class="czfh-thumb czfh-thumb--file"></div>').text('VIDEO');
         return $('<div class="czfh-thumb czfh-thumb--file"></div>').text((ext(it.name)||'FILE').toUpperCase());
       }
      function filterListItems(arr){
        // Do not silently hide DOCX/XLSX/other formats uploaded into "Отчёт".
        return Array.isArray(arr) ? arr.slice() : [];
      }

      function renderList(arr){
        authErrorVisible=false;
        stopMediaRefresh();
        var $el = $('#'+IDS.list);
        arr = filterListItems(arr);
        mediaLinks = Object.create(null);
        (arr || []).forEach(function(it){ cacheMedia(state.cat, it); });
        $el.empty();
        if (!arr || !arr.length) {
          $el.append($('<p class="czfh-muted"></p>').text(t('no_files','Нет файлов')));
          return;
        }
        var grid = $('<div class="czfh-grid"></div>');
        arr.forEach(function(it){
          var $card = $('<div class="czfh-card"></div>');
          $card.append(createThumbNode(it));
          var $meta = $('<div class="czfh-meta"></div>');
          $meta.append($('<div></div>').text(it.name || ''));
          $meta.append($('<div></div>').text(fmtSize(it.size) + ' • ' + fmtDate(it.lastmod)));
          if (state.canary && it.isSyntheticExample === true) {
            $meta.append($('<span class="czfh-test-label"></span>').text('СИНТЕТИЧЕСКИЙ ТЕСТ'));
          }
          $card.append($meta);
          if (isAudioItem(it)) {
            var $audio = $('<audio controls preload="none" class="czfh-audio"></audio>');
            $audio.append($('<source>').attr('src', fileUrl(it.name)));
            $card.append($audio);
          }
          var $actions = $('<div class="czfh-actions"></div>');
          var fh09WriteCard=fh09Active() && !!fh09Match(it.name);
          appendCommonActions($actions, state.cat, it.name, { preview: state.canary || state.production || isPdfItem(it), readOnly: state.canary && !fh09WriteCard, softDelete:state.production || fh09WriteCard });
          $card.append($actions);
          grid.append($card);
        });
        $el.append(grid);
        bindCommonCardActions($el);
        scheduleMediaRefresh(arr);
      }

       function loadList(){
         var leadId=String(state.leadId), category=state.cat;
         var eventContext=captureTelemetryContext();
         eventContext.lead_id=leadId;eventContext.category=category;
         var generation=++listRequestGeneration;
         var view=viewGeneration;
         function current(){
           return !destroyed && generation===listRequestGeneration && view===viewGeneration &&
             String(state.leadId)===leadId && state.cat===category &&
             !state.historyView && !state.trashView && !state.davView;
         }
         var url=listUrl();
         return amoRequest(url)
           .then(function(items){
             if(!current())return;
             renderList(items);
           })
           .catch(function(e){
             telemetry('list_failed',{feature:'files',http_status:e.status,error_code:e.code||'list_failed',context:eventContext});
             if(!current())return;
             // Для проблем с сессией показываем ТОЧНЫЙ текст: в нём уже сказано,
            // что именно делать пользователю. «Ошибка подключения к бэкенду»
            // оставляем только для настоящих сетевых сбоев — он сбивал с толку
            // и уводил в диагностику сети при живом бэкенде.
            var rateLimited=e&&(Number(e.status)===429||e.code==='gateway_rate_limited'||e.code==='rate_limited');
            var text=rateLimited
              ? 'Сервер временно ограничил частоту запросов из-за большого числа открытых окон. Подождите несколько секунд и повторите.'
              : ((e&&e.code&&(isSessionRecoveryCode(e.code)||e.code==='context_missing'))
                ? e.message
                : t('connect_error','Ошибка подключения к бэкенду') + ': ' + ((e&&e.message)||''));
            renderError(text,e&&e.code);
           });
       }
      // Server is the only source of action records. No browser-generated audit entries.
      function loadHistory(){
        var snapshot={generation:viewGeneration,lead:String(state.leadId),ctx:state.contextKey,category:state.cat};
        var eventContext=captureTelemetryContext(snapshot.ctx);
        eventContext.lead_id=snapshot.lead;eventContext.category=snapshot.category;
        function current(){return !destroyed&&viewGeneration===snapshot.generation&&
          String(state.leadId)===snapshot.lead&&state.contextKey===snapshot.ctx&&state.historyView;}
        var $host=$('#'+IDS.list).empty().append($('<p class="czfh-muted"></p>').text('Загрузка истории действий…'));
        return amoRequest(state.backend.base+'/activity?leadId='+enc(snapshot.lead)+'&limit=30')
          .then(function(data){
            if(!current())return;
            $host.empty();
            if(!data||!Array.isArray(data.events))throw new Error('invalid_activity_response');
            if(data.cloud_status==='delayed')$host.append($('<p class="czfh-warning"></p>').text('Синхронизация облачной истории задерживается.'));
            if(data.partial){var skipped=Number(data.skipped_count)||0;
              $host.append($('<p class="czfh-warning"></p>').text(skipped>0
                ?('Часть записей истории недоступна ('+skipped+'). Показаны не все события — сообщите администратору.')
                :'История загружена частично; повторите запрос позже.'));}
            if(data.has_more)$host.append($('<p class="czfh-muted"></p>').text('Показаны последние '+data.events.length+' действий.'));
            if(!data.events.length){
              if(!data.partial&&data.cloud_status!=='delayed')
                $host.append($('<p class="czfh-muted"></p>').text('Для этой сделки пока нет записей.'));
              return;
            }
            var names={upload:'Загрузил файл',rename:'Переименовал файл',trash:'Переместил в корзину',restore:'Восстановил файл'};
            data.events.forEach(function(it){
              if(!it||typeof it!=='object')return;
              var action=names[it.action]||'Действие с файлом';
              var who=it.actor_name?String(it.actor_name):('Сотрудник ID '+String(it.actor_id||'?'));
              var time=it.time?fmtDate(it.time):'';
              var $card=$('<div class="czfh-history-row"></div>');
              $card.append($('<div class="czfh-history-title"></div>').text(action));
              $card.append($('<div class="czfh-history-meta"></div>').text(time+' · '+who));
              if(it.file_name)$card.append($('<div class="czfh-history-file"></div>').text(String(it.file_name)));
              if(it.action==='rename'&&it.new_name)$card.append($('<div class="czfh-history-file"></div>').text('Новое имя: '+String(it.new_name)));
              if(it.category)$card.append($('<div class="czfh-history-meta"></div>').text('Категория: '+String(it.category)));
              $host.append($card);
            });
          })
          .catch(function(err){
            telemetry('request_failed',{feature:'files',http_status:err&&err.status,
              error_code:err&&err.code||'activity_failed',context:eventContext});
            if(current()){
              $host.empty();
              var unavailable=err&&[404,405,501,503].indexOf(Number(err.status))!==-1;
              $host.append($('<p class="czfh-error"></p>').text(unavailable?
                'Серверная история действий ещё не подключена. Файлы работают без изменений.':
                'Не удалось загрузить историю действий. Повторите позже.'));
            }
          });
      }
      function loadTrash(){
        var snapshot={generation:viewGeneration,lead:String(state.leadId),cat:state.cat,trash:state.trashView,dav:state.davView};
        var eventContext=captureTelemetryContext();
        eventContext.lead_id=snapshot.lead;eventContext.category=snapshot.cat;
        function current(){return !destroyed&&viewGeneration===snapshot.generation&&String(state.leadId)===snapshot.lead&&state.cat===snapshot.cat&&state.trashView===snapshot.trash&&state.davView===snapshot.dav;}
        if (state.production) {
          return amoRequest(state.backend.base+'/trash?leadId='+enc(state.leadId)+'&category='+enc(state.cat))
            .then(function(items){
              if(!current())return;
              var $host=$('#'+IDS.list).empty();
              if (!Array.isArray(items) || !items.length) {$host.append($('<p class="czfh-muted"></p>').text('Корзина пуста'));return;}
              items.forEach(function(it){
                var $card=$('<div class="czfh-card"></div>');
                $card.append($('<div class="czfh-meta"></div>').text(it.name+' — '+fmtSize(it.size)));
                $('<button type="button" class="czfh-view-tool">↩ Восстановить</button>').on('click',function(){
                  var $btn=$(this);if($btn.prop('disabled'))return;
                  var leadId=String(state.leadId),category=state.cat;
                  if(!confirm('Восстановить «'+it.name+'» из корзины?'))return;
                  $btn.prop('disabled',true);
                  amoRequest(state.backend.base+'/restore',{method:'POST',contentType:'application/json',
                    data:JSON.stringify({leadId:leadId,category:category,name:it.name})})
                    .then(function(){if(String(state.leadId)===leadId && state.cat===category && state.trashView)loadTrash();})
                    .catch(function(err){alert('Результат восстановления не подтверждён: '+err.message+'\nПроверьте список и корзину.');if(String(state.leadId)===leadId && state.cat===category)refreshCurrentCategory();})
                    .then(function(){$btn.prop('disabled',false);});
                }).appendTo($card);$host.append($card);
              });
            }).catch(function(e){
              telemetry('trash_failed',{feature:'trash',http_status:e.status,error_code:e.code||'trash_failed',context:eventContext});
              if(current())renderError('Корзина: '+e.message,e.code);
            });
        }
        return amoRequest(fh09Url('trash')+'?leadId='+enc(state.leadId)+'&category=other')
          .then(function(items){
            if(!current())return;
            var $host=$('#'+IDS.list).empty();
            $host.append($('<p class="czfh-warning"></p>').text('Локальная тестовая корзина: файлы сохраняются в постоянном хранилище RC02.'));
            if (!Array.isArray(items) || !items.length) {$host.append($('<p class="czfh-muted"></p>').text('Корзина пуста'));return;}
            items.forEach(function(it){
              if (!fh09Match(it.name)) return;
              var $card=$('<div class="czfh-card"></div>');
              $card.append($('<div class="czfh-meta"></div>').text(it.name+' — '+fmtSize(it.size)));
              $('<button type="button" class="czfh-view-tool">↩ Восстановить</button>').on('click',function(){
                amoRequest(fh09Url('restore'),{method:'POST',contentType:'application/json',
                  data:JSON.stringify({leadId:String(state.leadId),category:'other',name:it.name})})
                  .then(loadTrash).catch(function(err){alert('Восстановление: '+err.message);});
              }).appendTo($card);
              $host.append($card);
            });
          }).catch(function(e){if(current())renderError('Тестовая корзина: '+e.message);});
      }

      // FH11 is separate from the existing local FH09 test. No arbitrary file upload.
      function fh11Call(action, name, slug){
        var payload={leadId:String(state.leadId),category:'other',action:action};
        if (name) payload.name=name;
        if (slug) payload.new_slug=slug;
        return amoRequest(fh09Url('fh11'), {method:'POST',contentType:'application/json',data:JSON.stringify(payload)});
      }
      function loadDav(){
        if (!fh09Active() || !state.davView) return Promise.resolve();
        var generation=viewGeneration,leadId=String(state.leadId),cat=state.cat;
        function current(){return !destroyed&&generation===viewGeneration&&String(state.leadId)===leadId&&state.cat===cat&&state.davView;}
        return amoRequest(fh09Url('fh11')+'?leadId='+enc(state.leadId)+'&category=other')
          .then(function(data){
            if(!current())return;
            var $host=$('#'+IDS.list).empty();
            mediaLinks=Object.create(null);
            $host.append($('<p class="czfh-warning"></p>').text('Только автоматически сгенерированные TXT. Mail.ru /CRM/ivan0082/4_other. Данные пациентов запрещены.'));
            if (!data || !Array.isArray(data.files) || !Array.isArray(data.trash)) throw new Error('Некорректный FH11 API');
            if (data.pending) {
              $host.append($('<p class="czfh-error"></p>').text('Операция не подтверждена. Новые изменения заблокированы до сверки на сервере.'));
            }
            var $bar=$('<div class="czfh-dav-bar"></div>');
            $('<button type="button" class="czfh-view-tool"></button>').text('🧪 Создать TXT в Mail.ru')
              .prop('disabled',!!data.pending).on('click',function(){
                if (!confirm('Создать только синтетический TXT в тестовой папке Mail.ru?')) return;
                fh11Call('create').then(loadDav).catch(function(e){alert('Mail.ru: '+e.message);loadDav();});
              }).appendTo($bar);
            $host.append($bar,$('<div class="czfh-section-title"></div>').text('Активные синтетические файлы Mail.ru'));
            if (!data.files.length) $host.append($('<p class="czfh-muted"></p>').text('Пока нет файлов FH11'));
            data.files.forEach(function(it){
              if (!it || !fh11Name(it.name)) return;
              cacheMedia('other',it);
              var $card=$('<div class="czfh-card"></div>');
              $card.append($('<div class="czfh-meta"></div>').text(it.name+' • синтетический TXT в Mail.ru'));
              var $act=$('<div class="czfh-actions"></div>');
              $('<a href="#" class="czfh-preview"></a>').attr('data-name',it.name).attr('data-category','other').text('Просмотр').appendTo($act);
              $('<a target="_blank" rel="noopener noreferrer"></a>').attr('href',it.url||'#').text('Открыть').appendTo($act);
              $('<a download></a>').attr('href',it.downloadUrl||'#').text('Скачать').appendTo($act);
              $('<a href="#" class="czfh-copy"></a>').attr('data-link',it.url||'#').text('Ссылка').appendTo($act);
              $('<button type="button" class="czfh-view-tool"></button>').text('Переименовать').prop('disabled',!!data.pending).on('click',function(){
                var match=fh11Name(it.name);var next=prompt('Новое короткое имя: латиница, цифры, дефис (до 20 символов)',match[2]);
                if (next===null)return; next=String(next).trim().toLowerCase();
                if (!/^[a-z][a-z0-9-]{0,19}$/.test(next)){alert('Недопустимое имя');return;}
                fh11Call('rename',it.name,next).then(loadDav).catch(function(e){alert('Mail.ru: '+e.message);loadDav();});
              }).appendTo($act);
              $('<button type="button" class="czfh-view-tool"></button>').text('В корзину').prop('disabled',!!data.pending).on('click',function(){
                if (!confirm('Переместить синтетический файл в тестовую корзину Mail.ru?'))return;
                fh11Call('trash',it.name).then(loadDav).catch(function(e){alert('Mail.ru: '+e.message);loadDav();});
              }).appendTo($act);
              $card.append($act);$host.append($card);
            });
            $host.append($('<div class="czfh-section-title"></div>').text('Корзина Mail.ru (восстановление без DELETE)'));
            if (!data.trash.length) $host.append($('<p class="czfh-muted"></p>').text('Корзина пуста'));
            data.trash.forEach(function(it){
              if (!it || !fh11Name(it.name)) return;
              var $card=$('<div class="czfh-card"></div>');
              $card.append($('<div class="czfh-meta"></div>').text(it.name));
              $('<button type="button" class="czfh-view-tool"></button>').text('↩ Восстановить').prop('disabled',!!data.pending).on('click',function(){
                fh11Call('restore',it.name).then(loadDav).catch(function(e){alert('Mail.ru: '+e.message);loadDav();});
              }).appendTo($card);
              $host.append($card);
            });
            bindCommonCardActions($host);
          }).catch(function(e){if(current())renderError('Mail.ru тест: '+e.message);});
      }

      function createSyntheticTxt(){
        if (!fh09Active() || state.trashView) return;
        if (!window.crypto || !window.crypto.getRandomValues) {alert('Недоступен генератор случайных чисел');return;}
        var bytes=new Uint8Array(8); window.crypto.getRandomValues(bytes);
        var ident=Array.from(bytes).map(function(b){return b.toString(16).padStart(2,'0');}).join('');
        var name='fh09-'+ident+'-demo.txt';
        var content='FILEHUB_SYNTHETIC_ONLY_V1\nTest file ID: '+ident+'\n';
        var file=new File([content],name,{type:'text/plain'});
        var data=new FormData();
        data.append('leadId',String(state.leadId));data.append('category','other');data.append('file',file,name);
        return amoRequest(fh09Url('upload'),{method:'POST',data:data})
          .then(loadList).catch(function(err){alert('Синтетическая загрузка: '+err.message);});
      }

      function renderStats($host, meta){
        if (!meta) return;
        var stats = [
          { label: t('calls_updated_at','Обновлено'), value: fmtDate(meta.updatedAt) || '—' },
          { label: t('calls_count','Звонков'), value: meta.callsCount == null ? '—' : String(meta.callsCount) },
          { label: t('calls_first','Первый звонок'), value: fmtDate(meta.firstCallAt) || '—' },
          { label: t('calls_last','Последний звонок'), value: fmtDate(meta.lastCallAt) || '—' }
        ];
        if (meta.previousLeadIds && meta.previousLeadIds.length) stats.push({ label: t('calls_previous_leads','Предыдущие сделки'), value: meta.previousLeadIds.join(', ') });
        if (meta.mergedFromLeadIds && meta.mergedFromLeadIds.length) stats.push({ label: t('calls_merged_leads','Объединено из сделок'), value: meta.mergedFromLeadIds.join(', ') });
        var $wrap = $('<div class="czfh-calls-summary"></div>');
        $wrap.append($('<div class="czfh-section-title"></div>').text(t('calls_summary_title','Сводка по звонкам')));
        var $grid = $('<div class="czfh-stat-grid"></div>');
        stats.forEach(function(stat){
          var $item = $('<div class="czfh-stat"></div>');
          $item.append($('<div class="czfh-stat__label"></div>').text(stat.label));
          $item.append($('<div class="czfh-stat__value"></div>').text(stat.value));
          $grid.append($item);
        });
        $wrap.append($grid);
        $host.append($wrap);
      }

      function renderCallCard(call){
        var $card = $('<div class="czfh-call-card"></div>');
        var $head = $('<div class="czfh-call-head"></div>');
        var title = fmtDate(call.callAt) || '—';
        var subtitle = [];
        if (call.operatorName) subtitle.push(call.operatorName);
        if (call.amoStageName) subtitle.push(call.amoStageName);
        $head.append($('<div class="czfh-call-title"></div>').text(title));
        $head.append($('<div class="czfh-call-meta"></div>').text(subtitle.join(' • ') || t('calls_no_meta','Без доп. информации')));
        $card.append($head);
        if (call.summary) $card.append($('<div class="czfh-call-summary"></div>').text(call.summary));
        if (call.audio) {
          var $audio = $('<audio controls preload="none" class="czfh-audio"></audio>');
          $audio.append($('<source>').attr('src', fileUrlForCategory('calls', call.audio.name)));
          $card.append($audio);
          var $meta = $('<div class="czfh-meta"></div>');
          $meta.append($('<div></div>').text(call.audio.name));
          $meta.append($('<div></div>').text(fmtSize(call.audio.size) + ' • ' + fmtDate(call.audio.lastmod)));
          $card.append($meta);
          var $actions = $('<div class="czfh-actions"></div>');
          appendCommonActions($actions, 'calls', call.audio.name, { readOnly:true, preview: (state.canary || state.production) && isAudioItem(call.audio) });
          $card.append($actions);
        } else {
          $card.append($('<div class="czfh-warning"></div>').text(t('calls_audio_missing','Аудиофайл для этого звонка не найден в облаке.')));
        }
        return $card;
      }

      function renderOrphanAudio($host, items){
        if (!items || !items.length) return;
        $host.append($('<div class="czfh-section-title"></div>').text(t('calls_orphan_title','Не привязанные аудиофайлы')));
        var $grid = $('<div class="czfh-grid"></div>');
        items.forEach(function(it){
          var $card = $('<div class="czfh-card"></div>');
          $card.append($('<div class="czfh-thumb czfh-thumb--file"></div>').text('AUDIO'));
          var $meta = $('<div class="czfh-meta"></div>');
          $meta.append($('<div></div>').text(it.name || ''));
          $meta.append($('<div></div>').text(fmtSize(it.size) + ' • ' + fmtDate(it.lastmod)));
          $card.append($meta);
          var $audio = $('<audio controls preload="none" class="czfh-audio"></audio>');
          $audio.append($('<source>').attr('src', fileUrlForCategory('calls', it.name)));
          $card.append($audio);
          var $actions = $('<div class="czfh-actions"></div>');
          appendCommonActions($actions, 'calls', it.name, { readOnly:true, preview: (state.canary || state.production) && isAudioItem(it) });
          $card.append($actions);
          $grid.append($card);
        });
        $host.append($grid);
      }

      function renderCallsFeed(data){
        mediaLinks = Object.create(null);
        (data.timeline || []).forEach(function(call){ if (call.audio) cacheMedia('calls', call.audio); });
        (data.orphanAudio || []).forEach(function(it){ cacheMedia('calls', it); });
        var $el = $('#'+IDS.list);
        $el.empty();
        var $wrap = $('<div class="czfh-calls-feed"></div>');
        renderStats($wrap, data.summaryMeta || null);

        if (data.summaryError === 'summary_missing') {
          $wrap.append($('<div class="czfh-warning"></div>').text(t('calls_summary_missing','Файл summary.json не найден.')));
        } else if (data.summaryError === 'invalid_summary_json') {
          $wrap.append($('<div class="czfh-error"></div>').text(t('calls_summary_invalid','Файл summary.json поврежден или содержит некорректный JSON.')));
        } else if (data.summaryError === 'summary_fetch_failed') {
          $wrap.append($('<div class="czfh-error"></div>').text(t('calls_summary_fetch_failed','Не удалось загрузить summary.json из облака.')));
        }

        var timeline = data.timeline || [];
        if (timeline.length) {
          $wrap.append($('<div class="czfh-section-title"></div>').text(t('calls_history_title','История звонков')));
          timeline.forEach(function(call){ $wrap.append(renderCallCard(call)); });
        } else if (!data.orphanAudio || !data.orphanAudio.length) {
          $wrap.append($('<p class="czfh-muted"></p>').text(t('calls_no_history','История звонков пока пуста.')));
        }

        renderOrphanAudio($wrap, data.orphanAudio || []);
        $el.append($wrap);
        bindCommonCardActions($el);
      }

      function loadCallsFeed(){
        var generation=viewGeneration,leadId=String(state.leadId),cat=state.cat;
        var eventContext=captureTelemetryContext();
        eventContext.lead_id=leadId;eventContext.category=cat;
        function current(){return !destroyed&&generation===viewGeneration&&String(state.leadId)===leadId&&state.cat===cat&&!state.trashView&&!state.davView;}
        return amoRequest(callsFeedUrl())
          .then(function(data){if(current())renderCallsFeed(data);})
          .catch(function(e){
            telemetry('calls_failed',{feature:'calls',http_status:e.status,error_code:e.code||'calls_failed',context:eventContext});
            if(current())renderError(t('calls_feed_error','Ошибка загрузки истории звонков')+': '+e.message,e.code);
          });
      }

      function pickFiles(){
        return new Promise(function(resolve){
          var input=document.createElement('input');
          input.type='file'; input.multiple=true; input.style.display='none';
          var finished=false;
          function finish(files){if(finished)return;finished=true;input.remove();resolve(files);}
          input.addEventListener('change',function(){finish(Array.from(input.files||[]));},{once:true});
          input.addEventListener('cancel',function(){finish([]);},{once:true});
          document.body.appendChild(input);input.click();
        });
      }

      var MAX_UPLOAD_BYTES=20*1024*1024; // Confirmed backend limit. Never claim 50 MiB.
      function upload(files){
        if(uploadBusy || state.canary || isCallsCat() || state.trashView || state.davView)return;
        if(uploadQueueState&&uploadQueueState.paused){
          alert('Сначала проверьте остановленную очередь и очистите её в интерфейсе. Повторная отправка не выполняется автоматически.');
          return;
        }
        if(!files || !files.length)return;
        var oversize=files.filter(function(f){return !f || !Number.isFinite(f.size) || f.size>MAX_UPLOAD_BYTES || f.size<1;});
        if(oversize.length)alert('Лимит на один файл: 20 МиБ (20 971 520 байт). Отклонено пустых/слишком больших: '+oversize.length+'.');
        var queue=files.filter(function(f){return f && Number.isFinite(f.size) && f.size>0 && f.size<=MAX_UPLOAD_BYTES;});
        if(!queue.length)return;
        var leadId=String(state.leadId),category=state.cat,base=state.backend.base;
        var eventContext=captureTelemetryContext();
        eventContext.lead_id=leadId;eventContext.category=category;
        uploadBusy=true;
        uploadQueueState={lead:leadId,category:category,items:queue.map(function(f){return {file:f,status:'queued'};}),paused:false};
        $('#czfh_queue_actions').hide();
        $('#czfh_queue_items').empty();
        $('#'+IDS.btn).prop('disabled',true);
        var $bar=$('#'+IDS.progress),$fill=$('#'+IDS.fill),$status=$('#czfh_upload_status');
        $bar.show();$fill.css('width','0%');
        var total=queue.length,done=0;
        function finish(){uploadBusy=false;$('#'+IDS.btn).prop('disabled',false);$bar.hide();$fill.css('width','0%');}
        function update(){
          $fill.css('width',Math.round(done/total*100)+'%');
          $status.text('Загружено '+done+' из '+total+' файлов');
        }
        function displayQueue(){
          var $host=$('#czfh_queue_items').empty();
          if(!uploadQueueState)return;
          uploadQueueState.items.forEach(function(row){
            var label={verified:'Готово',uploading:'Результат не подтверждён',ambiguous:'Результат неизвестен',failed:'Ошибка до подтверждения',queued:'Не отправлен'};
            $('<div class="czfh-queue-row"></div>').text((label[row.status]||'Ожидает')+' — '+row.file.name).appendTo($host);
          });
        }
        update();
        function next(){
          var f=queue.shift();
          if(!f){finish();$status.text('Загрузка завершена: '+done+' из '+total+'.');
            uploadQueueState=null;$('#czfh_queue_items').empty();
            if(String(state.leadId)===leadId&&state.cat===category&&!state.trashView)loadList();return;}
          var item=uploadQueueState&&uploadQueueState.items.find(function(it){return it.file===f;});
          if(item)item.status='uploading';
          $status.text('Загрузка '+(done+1)+' из '+total+': '+f.name);
          var fd=new FormData();fd.append('leadId',leadId);fd.append('category',category);fd.append('file',f,f.name);
          amoRequest(base+'/upload',{method:'POST',data:fd,processData:false,contentType:false,telemetryContext:eventContext})
            .then(function(){if(item)item.status='verified';done++;telemetry('upload_verified',{feature:'upload'});update();next();})
            .catch(function(err){
              if(item)item.status=err&&err.ambiguous?'ambiguous':'failed';
              if(uploadQueueState){uploadQueueState.paused=true;}
              telemetry(err&&err.ambiguous?'upload_ambiguous':'upload_paused',
                {feature:'upload',http_status:err&&err.status,error_code:err&&err.code||(err&&err.ambiguous?'upload_ambiguous':'upload_failed'),context:eventContext});
              finish();$('#czfh_queue_actions').show();displayQueue();
              $status.text('Загрузка остановлена на файле '+(done+1)+' из '+total+'. Остальные файлы не отправлялись. Статус ошибки: '+(item&&item.status||'failed')+'.');
              alert(t('upload_error','Ошибка загрузки файла')+': '+err.message+'\nПроверьте список файлов перед новой попыткой. Автоматических повторов нет.');
              if(String(state.leadId)===leadId&&state.cat===category&&!state.trashView)loadList();
            });
        }
        next();
      }

      function bind(){
        var $root = $('#'+IDS.root); if(!$root.length) return;
        $root.off('click', '#'+IDS.btn).on('click', '#'+IDS.btn, function(){ if (state.canary) return createSyntheticTxt(); if (!isCallsCat() && !uploadBusy) pickFiles().then(upload).catch(function(err){if(err && err.name!=='AbortError')alert('Не удалось выбрать файлы: '+err.message);}); });
        $root.off('click','#'+IDS.trashBtn).on('click','#'+IDS.trashBtn,function(){if(!fh09Active() && !state.production)return;state.trashView=!state.trashView;state.historyView=false;refreshCurrentCategory();});
        $root.off('click','#'+IDS.historyBtn).on('click','#'+IDS.historyBtn,function(){if(!state.production)return;state.historyView=!state.historyView;state.trashView=false;state.davView=false;refreshCurrentCategory();});
        $root.off('click','#'+IDS.davBtn).on('click','#'+IDS.davBtn,function(){if(!fh09Active())return;state.davView=!state.davView;state.trashView=false;state.historyView=false;refreshCurrentCategory();});
        $root.off('click','#czfh_queue_review').on('click','#czfh_queue_review',function(){refreshCurrentCategory();});
        $root.off('click','#czfh_queue_clear').on('click','#czfh_queue_clear',function(){
          if(uploadBusy)return;
          if(uploadQueueState&&!confirm('Удалить локальный список остановленной очереди? Уже отправленные файлы на сервере не удаляются.'))return;
          uploadQueueState=null;$('#czfh_queue_actions').hide();$('#czfh_queue_items').empty();$('#czfh_upload_status').text('Очередь очищена локально.');
        });
        var $drop=$('#'+IDS.drop);
        $drop.off('drag dragstart dragend dragover dragenter dragleave drop')
          .on('drag dragstart dragend dragover dragenter dragleave drop', function(e){ e.preventDefault(); e.stopPropagation(); })
          .on('dragover dragenter', function(){ if (!isCallsCat() && !state.canary) $drop.css('border-color','#4c8bf7'); })
          .on('dragleave dragend drop', function(){ $drop.css('border-color','#d8dbe1'); })
          .on('drop', function(e){ if (isCallsCat() || state.canary || uploadBusy || state.trashView) return; var files=(e.originalEvent && e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files)||[]; if(files.length) upload(Array.from(files)); });
        $(document).off('click', '#'+IDS.modalClose).on('click', '#'+IDS.modalClose, closePreview);
        $('#'+IDS.modal).off('click').on('click', function(e){ if (e.target.id===IDS.modal) closePreview(); });
      }

      function getWidgetCodeSafe() { try { var st = self.get_settings && self.get_settings() || {}; if (st.widget_code) return st.widget_code; if (self.params && self.params.widget_code) return self.params.widget_code; } catch (e) {} return null; }
      function injectWhiteHeaderCSS() { try { var wcode = getWidgetCodeSafe(); if (!wcode || document.getElementById('czfh-white-by-code')) return; var css = '.widgets__widget[data-widget-code="'+wcode+'"] .widgets__top-inner,.widgets__widget[data-code="'+wcode+'"] .widgets__top-inner,.widgets__widget[data-widget-item*="'+wcode+'"] .widgets__top-inner,.widgets__widget[data-widget-code="'+wcode+'"] .widgets__widget__top,.widgets__widget[data-code="'+wcode+'"] .widgets__widget__top,.widgets__widget[data-widget-item*="'+wcode+'"] .widgets__widget__top{background:#fff !important; background-image:none !important;border-color:#e5e7ea !important; box-shadow:none !important;}.card-widgets__widget-' + wcode + ' .card-widgets__widget__head{background:#fff !important; background-image:none !important;border-color:#e5e7ea !important; box-shadow:none !important;}.card-widgets__widget-' + wcode + ' .card-widgets__widget__body,.card-widgets__widget-' + wcode + ' .card-widgets__widget__inner,.card-widgets__widget-' + wcode + ' .widgets__widget__content{background:#fff !important; border-color:#e5e7ea !important;}'; var s = document.createElement('style'); s.id = 'czfh-white-by-code'; s.type = 'text/css'; s.textContent = css; document.head.appendChild(s); } catch (e) {} }
      function markSidebarHeader() { try { var wcode = getWidgetCodeSafe(); if (!wcode) return; var nodes = document.querySelectorAll('#widgets_block .widgets__widget'); nodes.forEach(function(n){ var code = n.getAttribute('data-widget-code') || n.getAttribute('data-code') || n.getAttribute('data-widget_item') || ''; if (!code && n.dataset) code = n.dataset.widgetCode || n.dataset.code || n.dataset.widgetItem || ''; if (code && code.indexOf(wcode) !== -1 && !n.classList.contains('czfh-white')) n.classList.add('czfh-white'); }); } catch (e) {} }
      function observeSidebar(){
        if(sidebarObserver)return;
        var target=document.getElementById('widgets_block')||document.body;
        if(typeof MutationObserver==='function'){
          sidebarObserver=new MutationObserver(function(){if(!destroyed)markSidebarHeader();});
          sidebarObserver.observe(target,{childList:true,subtree:true});
        }
        sidebarTimer=setTimeout(markSidebarHeader,100);
        sidebarHandler=function(){if(!destroyed)setTimeout(markSidebarHeader,0);};
        document.addEventListener('page:fully:loaded',sidebarHandler);
      }

      var realCallbacks = {
        settings: function ($modal_body) {
          var s = self.get_settings() || {};
          $modal_body.find('form').html(''+
            '<div class="widget_settings_block">'+
              '<div class="widget_settings_block__item_field">'+
                '<div class="widget_settings_block__title">URL FileHub API (для совместимости; RC02 использует заданный адрес)</div>'+
                '<input type="text" class="text-input" name="backend_url" value="'+safeValue(s.backend_url || '')+'" placeholder="https://ваш-сайт.рф/wp-json/czfh/v1">'+
              '</div>'+
              '<div class="widget_settings_block__item_field">'+
                '<div class="widget_settings_block__title">API-ключ (legacy, не используется RC02)</div>'+
                '<input type="password" class="text-input" name="api_key" value="'+safeValue(s.api_key || '')+'">'+
              '</div>'+
            '</div>'
          );
          return true;
        },
        init: function(){
          destroyed=false;
          try {
            var st = self.get_settings()||{};
            var href = (st.path||'').replace(/\/?$/,'/') + 'style.css?v=' + (st.version||'1');
            if ($('link[href="'+href+'"]').length<1){ $('head').append('<link href="'+href+'" rel="stylesheet" type="text/css">'); }
          } catch(e){}
          injectWhiteHeaderCSS();
          observeSidebar();
          return true;
        },
        bind_actions: function(){ bind(); return true; },
        render: function(){
          try {
            var area = self.system && self.system().area || '';
            if (/^lcard/.test(area)) {
              self.render_template({ caption:{class_name:'Chinazub_FileHub', html:''}, body:'', render: html() });
              // The amoCRM sidebar may clip positioned descendants. Move the viewer to body.
              var $viewers=$('[id="'+IDS.modal+'"]');
              $viewers.slice(0,-1).remove();
              $viewers.last().appendTo(document.body);
              setTimeout(function () { var $wrap = $('#' + IDS.root).closest('.card-widgets__widget'); if ($wrap.length) $wrap.addClass('czfh-white'); }, 0);
              bind();
              drawTabs();
            }
            var st = self.get_settings()||{};
            var previousLead=state.leadId,previousContext=state.contextKey;
            state.leadId=null;
            if (/^lcard/.test(area) && typeof AMOCRM!=='undefined'&&AMOCRM&&AMOCRM.data&&AMOCRM.data.current_card)
              state.leadId=AMOCRM.data.current_card.id||(AMOCRM.data.current_card.get&&AMOCRM.data.current_card.get('id'))||null;
            state.contextKey=resolveContext();
            if(previousLead!==state.leadId||previousContext!==state.contextKey){
              state.historyView=false;state.trashView=false;
              clearPeerRecovery();authErrorVisible=false;
              if(previousContext&&previousContext!==state.contextKey){
                closePeerChannel();
                delete fhAuthShared[previousContext];
                try{sessionStorage.removeItem(FH_SESSION_STORAGE_PREFIX+previousContext);}catch(e){}
              }
              mediaLinks=Object.create(null);viewGeneration++;stopMediaRefresh();clearRefreshTimer();closePreview();
              if(uploadQueueState&&uploadQueueState.paused){
                uploadQueueState=null;$('#czfh_queue_actions').hide();$('#czfh_queue_items').empty();
              }
            }
            if (!state.leadId) {
              telemetry('context_missing',{feature:'auth',error_code:'lead_context_missing'});
              $('#'+IDS.list).html('<p class="czfh-error">⚠️ '+t('no_lead','Не удалось определить ID сделки')+'</p>'); return true;
            }
            if(!state.contextKey){telemetry('context_missing',{feature:'auth',error_code:'context_missing'});renderError(fhError('context_missing').message);return true;}
            ensurePeerChannel(state.contextKey);
            state.canary = false;
            state.production = true;
            installTelemetryLifecycle();
            var startupOperator=resolveOperatorId();
            if(startupOperator){
              loadPersistentTelemetry(startupOperator);
              if(telemetryEvents.length)scheduleTelemetryFlush(0);
            }
            var base = (st.backend_url||'').trim().replace(/\/+$/,'');
            var key = (st.api_key||'').trim();
            if (!state.canary && !state.production && (!base || !key)) {
              $('#'+IDS.list).html('<p class="czfh-error">⚠️ '+t('no_settings','Укажите URL и API-ключ в настройках виджета')+'</p>'); return true;
            }
            if (state.canary || state.production) {
              state.backend = { base: CANARY_API };
              // Staging build 2.8.8: no token/file information is written to console.
            } else {
              state.backend = { base: base, key: key };
            }
            updateModeUI();
            refreshCurrentCategory();
            if(state.contextKey && cachedCanarySession())scheduleCanaryRefresh(state.contextKey);
          } catch(e){telemetry('widget_render_failed',{feature:'widget',error_code:'widget_render_failed'});renderError('Ошибка инициализации FileHub. Перезагрузите карточку.');}
          return true;
        },
        onSave: function () {
          var backend_url = $('input[name="backend_url"]').val() || '';
          var api_key = $('input[name="api_key"]').val() || '';
          self.set_settings({ backend_url: backend_url.trim().replace(/\/+$/, ''), api_key: api_key.trim() });
          return true;
        },
        destroy: function () {
          // Best-effort final delivery while the context is still valid. The queue
          // remains in localStorage until a confirmed 2xx, so this is safe to lose.
          flushTelemetry({keepalive:true,final:true,direct:true});
          removeTelemetryLifecycle();
          destroyed=true;viewGeneration++;if(telemetryTimer!==null){clearTimeout(telemetryTimer);telemetryTimer=null;}clearPeerRecovery();stopMediaRefresh();clearRefreshTimer();closePeerChannel();closePreview();
          $('[id="'+IDS.modal+'"]').remove();$(document).off('click','#'+IDS.modalClose);
          if(sidebarObserver){sidebarObserver.disconnect();sidebarObserver=null;}
          if(sidebarTimer!==null){clearTimeout(sidebarTimer);sidebarTimer=null;}
          if(sidebarHandler){document.removeEventListener('page:fully:loaded',sidebarHandler);sidebarHandler=null;}
          $('#'+IDS.root).off();$('#'+IDS.drop).off();
        }
      };

      this.callbacks = realCallbacks;
    } catch (e) {
      // Do not log raw exceptions: they may include API URLs or request data.
      if(typeof console!=='undefined'&&console.warn)console.warn('FileHub widget initialization failed');
    }

    return this;
  };
  return CustomWidget;
});
