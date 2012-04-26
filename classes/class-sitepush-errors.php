<?php

/**
 * Holds and displays errors. An error can be of 4 types:-
 *   - fatal-error
 *   - error
 *   - warning
 *   - notice
 *
 * When displaying errors, only errors of the most serious type are shown.
 */
class SitePushErrors
{
	public static $errors = array();
	public static $force_show_wp_errors = FALSE;

	static public function add_error( $message, $type='error', $field=NULL )
	{
		if( is_null($field) )
			self::$errors[$type][] = $message;
		else
			self::$errors[$type][$field] = $message;
	}

	static public function count_errors( $type=NULL )
	{
		$count = 0;
		if( is_null($type))
		{
			foreach( self::$errors as $error_type )
			{
				$count += count( $error_type );
			}
		}
		elseif( 'all-errors'==$type )
		{
			$count = self::count_errors( 'error' ) + self::count_errors( 'fatal-error' );
		}
		elseif( array_key_exists( $type, self::$errors ))
		{
			$count = count( self::$errors[$type] );
		}

		return $count;
	}

	static public function is_error( $type=NULL )
	{
		if( is_null($type) )
			return (bool) ( self::count_errors( 'fatal-error' ) + self::count_errors( 'error' ) );
		else
			return (bool) self::count_errors( $type );
	}

	static public function errors( $force_type=NULL )
	{
		$errors = self::$errors;
		$show_wp_errors = self::$force_show_wp_errors || get_transient('sitepush_force_show_wp_errors');
		delete_transient('sitepush_force_show_wp_errors');

		if( is_null($force_type) || 'all' == $force_type )
		{
			//show the most serious errors only
			foreach( array( 'fatal-error', 'error') as $type )
			{
				if( !empty(self::$errors[$type]) )
				{
					echo self::get_error_html( $type );
					if( $show_wp_errors ) settings_errors();
					if( 'all' <> $force_type ) return;
				}
			}

			//if no errors, show warnings, notices etc
			foreach( array( 'warning', 'notice' ) as $type )
			{
				if( !empty(self::$errors[$type]) )
					echo self::get_error_html( $type );
			}

			settings_errors();
		}
		else
		{
			if( !empty(self::$errors[$force_type]) )
			{
				echo self::get_error_html( self::$errors[$force_type], $force_type );
				unset( self::$errors[$force_type] );
			}
		}
	}

	private static function get_error_html( $type='error' )
	{
		$output = '';
		$class = in_array( $type , array( 'fatal-error', 'error') ) ? 'error' : 'updated';
		foreach(  self::$errors[$type] as $error )
		{
			$output .= "<div class='{$class}'><p><strong>{$error}</strong></p></div>";
		}
		unset( self::$errors[$type] );
		return $output;
	}

	public static function force_show_wp_errors()
	{
		self::$force_show_wp_errors = TRUE;
		set_transient('sitepush_force_show_wp_errors', TRUE, 30);
	}

}

/* EOF */