proxy_pass https://127.0.0.1:18444;
proxy_ssl_verify off;
proxy_ssl_server_name off;

proxy_set_header Host $host;
proxy_set_header X-Forwarded-Proto https;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $remote_addr;
proxy_set_header X-Auth-Token $http_x_auth_token;
proxy_set_header X-Request-ID $request_id;

proxy_connect_timeout 5s;
proxy_http_version 1.1;
proxy_request_buffering off;
proxy_send_timeout 300s;
proxy_read_timeout 600s;
