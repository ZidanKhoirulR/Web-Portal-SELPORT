<?php

class LandingController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function index($f3)
    {
        echo Template::instance()->render('landing.html');
    }
}
