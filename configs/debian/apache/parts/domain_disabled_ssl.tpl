<VirtualHost {DOMAIN_IPS}>
    ServerAdmin webmaster@{DOMAIN_NAME}
    ServerName {DOMAIN_NAME}
    ServerAlias www.{DOMAIN_NAME} {ALIAS}.{BASE_SERVER_VHOST}

    DocumentRoot {USER_WEB_DIR}/domain_disabled_pages

    DirectoryIndex index.html

    LogLevel error
    ErrorLog {HTTPD_LOG_DIR}/{DOMAIN_NAME}/error.log

    <Directory {USER_WEB_DIR}/domain_disabled_pages>
        Options None
        AllowOverride None
        Require all granted
    </Directory>

    SSLEngine On
    SSLCertificateFile      {CERTIFICATE}
    SSLCertificateChainFile {CERTIFICATE}

    Header always set Strict-Transport-Security "max-age={HSTS_MAX_AGE}{HSTS_INCLUDE_SUBDOMAINS}"
    RedirectMatch 303 ^/(?!(?:images/.+|index\.html|$)) https://www.{DOMAIN_NAME}/
</VirtualHost>
