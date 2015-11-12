<?php

// check for config file
if (count($argv) < 2) {
    exit(1);
}

require 'module.php';
require 'modules/OpenHouse.php';

$configString = file_get_contents($argv[1]);
$configuration = json_decode($configString);
$openHouse = new OpenHouse($configuration);
$openHouse->initialize();