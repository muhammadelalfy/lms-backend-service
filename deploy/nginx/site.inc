# Shared by the HTTP and HTTPS servers in default.conf.template.
server_tokens off;
client_max_body_size 25m;

    location /app/ {
        proxy_pass http://reverb;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $forwarded_proto;
        proxy_read_timeout 3h;
    }

    # The landing page is the front door on every host, school sites included.
    location = / {
        proxy_pass http://api;
        include /etc/nginx/templates/proxy-headers.inc;
    }

    location ~ ^/(brand|landing|logo|vendor)/|^/(favicon\.ico|robots\.txt)$ {
        proxy_pass http://api;
        include /etc/nginx/templates/proxy-headers.inc;
        expires 7d;
    }

    location ~ ^/(api|broadcasting|storage|download|up)(/|$) {
        proxy_pass http://api;
        include /etc/nginx/templates/proxy-headers.inc;
        proxy_read_timeout 60s;
    }

    location / {
        proxy_pass http://$site_upstream;
        include /etc/nginx/templates/proxy-headers.inc;
    }
