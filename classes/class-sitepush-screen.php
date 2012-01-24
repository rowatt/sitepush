<?php

class SitePush_Screen
{

	protected $options; //array with all options
	protected $plugin; //obj for main plugin
	
	public function __construct( $plugin, $options )
	{
		$this->plugin = $plugin;	
		$this->options = $options;	
	}

}

/* EOF */