<?php

require_once 'vendor/autoload.php';
use ParserCore\Parser;

$model = new Parser();
$model->getInitInfo($argv[1]);

