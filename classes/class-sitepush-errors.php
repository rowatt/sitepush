<?php

/**
 * Holds and displays errors. An error can be of 4 types:-
 *   - fatal_error
 *   - error
 *   - warning
 *   - notice
 *
 * When displaying errors, only errors of the most serious type are shown.
 */
class SitePushErrors
{
	public static $errors = array();
	private static $error_types = array( 'fatal_error', 'error', 'warning', 'notice' );

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
		if( is_null($force_type) || 'all' == $force_type )
		{
			foreach( self::$error_types as $type )
			{
				if( !empty(self::$errors[$type]) )
				{
					echo self::get_error_html( self::$errors[$type], $type );
					unset( self::$errors[$type] );
					if( 'all' <> $force_type ) return;
				}
			}
			//if no errors of our own, show any errors/notices from WP settings_errors
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

	private static function get_error_html( $errors=array(), $type='error' )
	{
		$output = '';
		$class = 'notice'==$type ? 'updated' : 'error';
		foreach( $errors as $error )
		{
			$output .= "<div class='{$class}'><p><strong>{$error}</strong></p></div>";
		}
		return $output;
	}

}

/* EOF */