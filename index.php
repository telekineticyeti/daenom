<?php
	/**
	 * If no query string, terminate script.
	 */
	if (empty($_SERVER['QUERY_STRING'])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
		exit();
	// If query string exists, parse to a usable object
	} else {
		parse_str($_SERVER['QUERY_STRING'], $query);
	}

	/**
	 * Uses a CURL header request to retrieve the longform format of a shortened URL.
	 * @param  string	$url	Shortform URL (Example: http://t.co/id)
	 * @return string		    Longform URL (Example: http://twitter.com/User/status/ID)
	 */
	function unshorten_url($url) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_SSL_VERIFYHOST => FALSE, // suppress certain SSL errors
			CURLOPT_SSL_VERIFYPEER => FALSE,
			CURLOPT_NOBODY => TRUE // Header request only
		));
		curl_exec($ch);
		return curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	}

	/**
	 * Load Twitter API and perform token auth.
	 */
	function api_twitter() {
		require_once('vendor/j7mbo/twitter-api-php/TwitterAPIExchange.php');

		$twitter_tokens = array(
			'oauth_access_token' => getenv('TWITTER_TOKEN'),
			'oauth_access_token_secret' => getenv('TWITTER_TOKEN_SECRET'),
			'consumer_key' => getenv('TWITTER_CONSUMER_KEY'),
			'consumer_secret' => getenv('TWITTER_CONSUMER_SECRET')
		);

		return new TwitterAPIExchange($twitter_tokens);
	}

	/**
	 * Load Twitter API and perform token auth.
	 */
	function api_tumblr() {
		require __DIR__ . '/vendor/autoload.php';

		$tumblr_tokens = array(
			'token' => getenv('TUMBLR_TOKEN'),
			'token_secret' => getenv('TUMBLR_TOKEN_SECRET'),
			'consumer_key' => getenv('TUMBLR_CONSUMER_KEY'),
			'consumer_secret' => getenv('TUMBLR_CONSUMER_SECRET'),
		);

		$tumblr = new Tumblr\API\Client($tumblr_tokens['consumer_key'], $tumblr_tokens['consumer_secret']);
		$tumblr->setToken($tumblr_tokens['token'], $tumblr_tokens['token_secret']);

		return $tumblr;
	}

	/**
	 * Function for outputting debug messages
	 */
	function pre_echo($output, $class=false) {
		$class = (isset($class) ? $class : false);
		echo "<pre class=\"$class\">" . str_replace("\t", "", $output) . "</pre>";
	}


	/**
	 * Stitch multiple images together, combining them into one image using IM
	 * @param  array $images array of image URL's
	 * @return blob         combined image ready for output
	 */
	function multi_image_stitch($images) {
		if (!extension_loaded('imagick')) {
			die('ImageMagick is not installed, so multi-post images can not be supported.');
		}

		$im = new Imagick();

		foreach ($images as $key => $value) {
			$image_url = $value;
			$image_ext = array_pop((array_slice( explode('.', $image_url), -1)));
			$image_name = basename($image_url);
			$im->readImage($image_url);
		}

		$im->resetIterator();
		$combined = $im->appendImages(true);

		$combined->setCompression(Imagick::COMPRESSION_JPEG);
		$combined->setCompressionQuality(90);
		return $combined;
	}

	// Get longform URL
	if (isset($query['url'])) $long_url = unshorten_url($query['url']); 

	// Determine retrieval method
	$method = (isset($query['method']) ? $query['method'] : false);



	/**
	 * Twitter Query Handling
	 */
	if ($method == "twitter") {
		// Load Twitter API
		$twitter = api_twitter();

		// Deterimine ID from URL
		preg_match("/\/(\d+)\/?/is", $long_url, $matches);
		$id = $matches[1];

		// Prepare & Send API Request
		$url = 'https://api.twitter.com/1.1/statuses/show.json';
		$getfield = '?id=' . $id;
		$requestMethod = 'GET';

		$response = json_decode($twitter->setGetfield($getfield)
			->buildOauth($url, $requestMethod)
			->performRequest(), TRUE);

		// If no media present, abort with error code 404
		if (!isset($response['extended_entities'])) {
			// No media detected in main tweet, check if there is a quoted status that has media attached
			if (!isset($response['quoted_status']['extended_entities'])) {
				header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404);
				exit();
			} else {
				$media = $response['quoted_status']['extended_entities']['media'];
			}
		} else {
			$media = $response['extended_entities']['media'];
		}

		// Output Single Image/Media
		if (sizeof($media) <= 1) {
			foreach ($media as $k => $v) {
				$type =  $v['type'];

				// GIF
				if ($type == "animated_gif") {
					$media_url = $v['video_info']['variants'][0]['url'];
				// Photo
				}
				else if ($type == "photo") {
					$media_url = $v['media_url'];
				}
				// MP4
				else if ($type == "video") {
					$mp4_sources = [];
					// Filter video sources that have a defined bitrate into array
					foreach ($v['video_info']['variants'] as $k => $v) {
						if (isset($v['bitrate']))
							$mp4_sources[$v['bitrate']] = $v['url'];
					}
					// Set media URL to url of the video that has the highest bitrate
					$media_url = $mp4_sources[max(array_keys($mp4_sources))];
				}
			}
			header("Location: $media_url");
		}

		// Output Multi Image
		else if (sizeof($media) > 1) {
			$images = [];

			foreach ($media as $k => $v) {
				array_push($images, $v['media_url']);
			}

			header("Content-Type: image/jpg");
			echo multi_image_stitch($images);
		}
		exit();
	}


	/**
	 * Tumblr Query Handling
	 */
	if ($method == "tumblr") {
		// Load Tumblr API
		$tumblr = api_tumblr();

		// Determine username from URL
		preg_match("/^(?:https?:\/\/)?(?:[^@\n]+@)?(?:www\.)?([^:\/\n]+)/is", $long_url, $match_username);
		$user = $match_username[1];

		// Determine Post ID from URL
		preg_match("/\d{11,}/is", $long_url, $match_id);
		$id = $match_id[0];

		// Perform API Call
		$call = $tumblr->getBlogPosts($user, array('id' => $id));

		$result = json_decode(json_encode($call), true);
	
		// If no media present, abort with error code 404
		if (!isset($result['posts'][0]['photos'])) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404);
			exit();
		}

		$photos = $result['posts'][0]['photos'];

		// Output Single Image
		if (sizeof($photos) <= 1) {
			header("Location: " . $photos[0]['original_size']['url']);
		}
		
		// Output Multi Image
		else if (sizeof($photos) > 1) {
			$images = [];

			foreach ($photos as $k => $v) {
				array_push($images, $v['original_size']['url']);
			}

			header("Content-Type: image/jpg");
			echo multi_image_stitch($images);
		}
		exit();
	}


	/**
	 * Debug Query Handlers
	 * 
	 */
	if (isset($query['debug'])) {
		$debug_method = $query['debug'];
		$debug_output = "ORIGINAL URL: " . $query['url'] . "\n";
		$debug_output .= "LONG URL: $long_url\n";

		// Query Method: Twitter
		// Example usage: http://daenom.host/?debug=twitter&url=http://sample.url
		if ($debug_method == "twitter") {
			$debug_output .= "METHOD: $debug_method\n";

			// Determine ID from URL
			preg_match("/\/(\d+)\/?/is", $long_url, $matches);
			$id = $matches[1];

			$debug_output .= "USER ID: $id\n";

			// Load API, Prepare & send API request
			$twitter = api_twitter();

			$url = 'https://api.twitter.com/1.1/statuses/show.json';
			$getfield = '?id=' . $id;
			$requestMethod = 'GET';

			$call = json_decode($twitter->setGetfield($getfield)
				->buildOauth($url, $requestMethod)
				->performRequest(), TRUE);

			$json = htmlentities(json_encode($call, JSON_PRETTY_PRINT));
		}

		// Query Method: Tumblr
		// Example usage: http://daenom.host/?debug=tumblr&url=http://sample.url
		if ($debug_method == "tumblr") {
			$debug_output .= "METHOD: $debug_method\n";

			// Determine username from URL
			preg_match("/^(?:https?:\/\/)?(?:[^@\n]+@)?(?:www\.)?([^:\/\n]+)/is", $long_url, $match_username);
			$user = $match_username[1];
			$debug_output .= "USER NAME: $user\n";

			// Determine Post ID from URL
			preg_match("/\d{11,}/is", $long_url, $match_id);
			$id = $match_id[0];
			$debug_output .= "USER ID: $id\n";

			// Load Tumblr API & Perform Call
			$tumblr = api_tumblr();
			$call = $tumblr->getBlogPosts($user, array('id' => $id));
			$json = htmlentities(json_encode($call, JSON_PRETTY_PRINT));
		}

		pre_echo($debug_output);
		pre_echo($json, "json");
?>

<!-- Render the debug in a nice readable format -->
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
<script>
	$(function() {
		function syntaxHighlight(json) {
			if (typeof json != 'string') {
				 json = JSON.stringify(json, undefined, 2);
			}
			json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
			return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
				var cls = 'number';
				if (/^"/.test(match)) {
					if (/:$/.test(match)) {
						cls = 'key';
					} else {
						cls = 'string';
					}
				} else if (/true|false/.test(match)) {
					cls = 'boolean';
				} else if (/null/.test(match)) {
					cls = 'null';
				}
				return '<span class="' + cls + '">' + match + '</span>';
			});
		}
		var json = $('.json').text();
		$('.json').html(syntaxHighlight(json))
	});
</script>

<style type="text/css">
	pre { outline: 1px dashed #ccc; margin: 10px 10px 30px; padding: 10px; font-size: 12px; white-space: pre-wrap; word-wrap:break-word; }
	.string { color: green; }
	.number { color: darkorange; }
	.boolean { color: blue; }
	.null { color: magenta; }
	.key { color: red; }
</style>

<?php
	}
?>