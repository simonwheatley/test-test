<?php

echo "Using getenv" . PHP_EOL;

//print_r( $_ENV );

//phpinfo( INFO_CONFIGURATION );

var_dump( getenv( 'WORDPRESS_FAKE_MAIL_DIR' ) );