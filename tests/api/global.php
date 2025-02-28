<?php


function request( $verb, $base, $path, $content = '', $timestamp = '' )
{
	global $apiID, $apiSecret;
	$params     = array(
		'host'          => 'dev.siggy.borkedlabs.com',
		'content-type'  => 'application/json',
		'user-agent'    => 'apitest',
		'connection'    => 'keep-alive',
	);

	if($timestamp = '') {
		$timestamp = date('c');
	}
	
	$content_hash = "";
	$content_type = "";
	if($verb != "GET")
	{
		$content_hash = base64_encode(hash('sha256', $content, true));
		$content_type = "";
	}
	
	$stringToSign = $verb . "\n".
					$path . "\n".
					$timestamp . "\n".
					$content_type . "\n".
					$content_hash;

	$hash = base64_encode(hash_hmac('sha256', $stringToSign, $apiSecret, true));
	$authorization = $apiID.":".$hash;

	$params['Authorization'] = 'siggy-HMAC-SHA256 Credential='.$authorization;
	$params['x-siggy-date'] = $timestamp;

	$ch  = curl_init();

	$curl_headers = array();
	foreach( $params as $p => $k )
	{
		$curl_headers[] = $p . ": " . $k;
	}
	
	curl_setopt($ch, CURLOPT_URL,$base.$path);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
	curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false );
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false );

	// debug opts
	{
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$verbose = fopen('php://temp', 'rw+');
		curl_setopt($ch, CURLOPT_STDERR, $verbose);
		$result = curl_exec($ch); // raw result
		rewind($verbose);
		$verboseLog = stream_get_contents($verbose);
		echo "Verbose information:\n<pre>", htmlspecialchars($verboseLog), "</pre>\n";
	}

	echo prettyPrint( $result );
}

function url()
{
	return sprintf(
		"%s://%s/",
		'http',
		"dev.siggy.borkedlabs.com"
	);
}
/*
http://stackoverflow.com/questions/6054033/pretty-printing-json-with-php
*/
function prettyPrint( $json )
{
    $result = '';
    $level = 0;
    $in_quotes = false;
    $in_escape = false;
    $ends_line_level = NULL;
    $json_length = strlen( $json );

    for( $i = 0; $i < $json_length; $i++ ) {
        $char = $json[$i];
        $new_line_level = NULL;
        $post = "";
        if( $ends_line_level !== NULL ) {
            $new_line_level = $ends_line_level;
            $ends_line_level = NULL;
        }
        if ( $in_escape ) {
            $in_escape = false;
        } else if( $char === '"' ) {
            $in_quotes = !$in_quotes;
        } else if( ! $in_quotes ) {
            switch( $char ) {
                case '}': case ']':
                    $level--;
                    $ends_line_level = NULL;
                    $new_line_level = $level;
                    break;

                case '{': case '[':
                    $level++;
                case ',':
                    $ends_line_level = $level;
                    break;

                case ':':
                    $post = " ";
                    break;

                case " ": case "\t": case "\n": case "\r":
                    $char = "";
                    $ends_line_level = $new_line_level;
                    $new_line_level = NULL;
                    break;
            }
        } else if ( $char === '\\' ) {
            $in_escape = true;
        }
        if( $new_line_level !== NULL ) {
            $result .= "\n".str_repeat( "\t", $new_line_level );
        }
        $result .= $char.$post;
    }

    return $result;
}
