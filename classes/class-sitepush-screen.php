<?php

class SitePush_Screen
{
	/**
	 * @var SitePushOptions
	 */
	protected $options; //array with all options

	/**
	 * @var SitePushPlugin
	 */
	protected $plugin; //obj for main plugin
	
	public function __construct( $plugin )
	{
		$this->plugin = $plugin;	
		$this->options = $plugin->options;	
	}

}

/* EOF */