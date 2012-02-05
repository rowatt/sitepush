<?php

class SitePush_Screen
{

	protected $options; //array with all options
	protected $plugin; //obj for main plugin
	
	public function __construct( $plugin )
	{
		$this->plugin = $plugin;	
		$this->options = $plugin->options;	
	}

}

/* EOF */