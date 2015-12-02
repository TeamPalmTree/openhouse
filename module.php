<?php

abstract class Module {

    function __construct($configuration)
    {
        $this->configuration = $configuration;
    }

    protected function log($message) {
        $message = date('Y-m-d H:i:s') . ' ' . $message;
        error_log($message, 3, '/var/log/openhouse');
    }

    abstract function initialize();
    abstract function entered();
    abstract function occupied();
    abstract function vacant();

}