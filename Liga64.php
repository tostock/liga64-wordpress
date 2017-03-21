<?php
/*
Plugin Name: Liga64
Plugin URI: http://www.liga64.de/
Description: Das offizielle Plugin von Liga64 um Tabellen auf Wordpress-Seiten einzubinden.
Version: 0.1.0
Author: Tobias Stock <tobias@liga64.de>
Author URI: http://www.liga64.de/
*/

add_action('admin_menu', 'liga64_admin_actions');

// add shortcode
add_shortcode('liga64_tabelle', 'liga64_tabelle');



function liga64_tabelle($atts) {
	$atts = shortcode_atts(
		array('id' => '',
					'breite' => '',
					'zuletzt' => '',
					'tag' => ''
		), $atts);

	if(isset($atts['breite']) && $atts['breite'] != '') {
		$breite = $atts['breite'];
	}
	else {
		$breite = '100%';
	}
	$breiteStyle = $breite;

	$tabellenDaten = liga64_requestTabelle($atts['id'], $atts['tag']);
	$tabellenDaten = json_decode($tabellenDaten);

	ob_start();
	$output = '';
	//print_r($atts);
	$output = ob_get_contents();

	$tabelle  = '';
	if($breiteStyle != '100%') {
		$tabelle .= '<table class="liga64-tabelle table table-bordered table-hover" width="'.$breite.'" border="1" cellpadding="5" cellspacing="0" style="width: '.$breite.';">';
	}
	else {
		$tabelle .= '<table class="liga64-tabelle table table-bordered table-hover" width="'.$breite.'" border="1" cellpadding="5" cellspacing="0">';
	}
	$tabelle .= "  <tbody>\r\n";
	$tabelle .= "    <tr>\r\n";
	$tabelle .= "      <th>Rang</th>\r\n";
	$tabelle .= "      <th>Mannschaft</th>\r\n";
	$tabelle .= "      <th>Punkte</th>\r\n";
	if(isset($tabellenDaten[0]->duelle))
		$tabelle .= "      <th>Duelle</th>\r\n";
	$tabelle .= "      <th>Ringe</th>\r\n";
	$tabelle .= "    </tr>\r\n";
	//for($i = 0; $i < count($tabellenDaten); $i++) {
	foreach($tabellenDaten as $obj) {
		$tabelle .= "    <tr>\r\n";
		$tabelle .= "      <td>$obj->rang</td>\r\n";
		$tabelle .= "      <td>$obj->name</td>\r\n";
		$tabelle .= "      <td>$obj->punkte:$obj->punkte_verloren</td>\r\n";
		if(isset($obj->duelle))
			$tabelle .= "      <td>$obj->duelle:$obj->duelle_verloren</td>\r\n";
		$tabelle .= "      <td>$obj->ringe:$obj->ringe_verloren</td>\r\n";
		$tabelle .= "    </tr>\r\n";
	}
	$tabelle .= '  </tbody>';
	$tabelle .= '</table>';


	$output .= $tabelle;
	ob_end_clean();
	return $output;
}

function liga64_admin_actions() {
	add_options_page('Liga64', 'Liga64', 'manage_options', __FILE__, 'liga64_admin');
}

function isJSONString($str) {
	$isJSON = false;
	if(is_string($str)) {
		if(is_object(json_decode($str))) {
			$isJSON = true;
		}
		elseif(is_array(json_decode($str))) {
			$isJSON = true;
		}
		else
			$isJSON = false;
	}
	return $isJSON;
}

function liga64_requestTabelle($ligaId, $tag) {
	$liga64options = get_option('liga64_options');

	// use a HTTP POST instead of curl
	$host     = $liga64options['liga64url'];
	$apikey   = $liga64options['liga64apikey'];

	$daten = 'apikey='.$apikey;

	$pfad = '/api/getJsonTabelle/'.$ligaId;
	if(isset($tag) && $tag != '')
		$pfad .= '/'.$tag;

	$urlParams = parse_url($host);
	$socket = fsockopen($urlParams['host'], 80);

	$postData  = "POST ".$urlParams['path'].$pfad." HTTP/1.1\r\n";
	$postData .= "Host: ".$urlParams['host']."\r\n";
	$postData .= "Content-Type: application/x-www-form-urlencoded; charset=UTF-8\r\n";
	$postData .= "Content-length: ". strlen($daten) ."\r\n";
	$postData .= "Connection: close\r\n\r\n";
	$postData .= $daten;
	fputs($socket, $postData);

	$res = "";
	while(!feof($socket)) {
		$res .= fgets($socket, 128);
	}
	fclose($socket);

	$matches = array();
	preg_match('^\[.*?\]^', $res, $matches);

	$res = substr($res, strpos($res,"\r\n\r\n")+4);
	$res = explode("\r\n", $res);

	for($i = 0; $i < count($res); $i++) {
		if(isJSONString($res[$i]))
			return $res[$i];
	}
}


function liga64_requestAPIKey() {
	$liga64options = get_option('liga64_options');

	// use a HTTP POST instead of curl
	$host     = $liga64options['liga64url'];
	$referer  = $liga64options['liga64referer'];
	$comment  = $liga64options['liga64comment'];

	$pfad = '/api/registerPage/';
	$daten = '&host=' . $host . '&referer=' . $referer . '&comment=' . $comment;

	$urlParams = parse_url($host);
	$socket = fsockopen($urlParams['host'], 80, $errno, $errstr, 50);
	if (!$socket) {
		echo "not connected";
		return "";
	}
	else {
		$postData  = "POST ".$urlParams['path'].$pfad." HTTP/1.1\r\n";
		$postData .= "Host: ".$urlParams['host']."\r\n";
		$postData .= "Referer: $referer\r\n";
		$postData .= "Content-Type: application/x-www-form-urlencoded; charset=UTF-8\r\n";
		$postData .= "Content-length: ". strlen($daten) ."\r\n";
		$postData .= "Connection: close\r\n\r\n";
		$postData .= $daten;
		fputs($socket, $postData);

		$res = "";
		while(!feof($socket)) {
			$res .= fgets($socket, 128);
		}
		fclose($socket);
		$matches = array();
		preg_match('^\[.*?\]^', $res, $matches);

		$res = substr($res, strpos($res,"\r\n\r\n")+4);
		$res = explode("\r\n", $res);

		for($i = 0; $i < count($res); $i++) {
			if(preg_match('/^[a-f0-9]{32}$/', $res[$i]))  {
				return $res[$i];
			}
		}
	}
}



function liga64_admin() {
	$requestURI = $_SERVER['REQUEST_URI'];

	global $wpdb;

	if(isset($_GET["requestAPIKey"])) {
	$apikey = liga64_requestAPIKey();
	$liga64options = get_option('liga64_options');
	$liga64options['liga64apikey'] = $apikey;
	update_option('liga64_options', $liga64options);
	}

	if(isset($_POST['submit']))
	{
		$liga64options = array();
		$liga64options['liga64url'] = $_POST['liga64url'];
		$liga64options['liga64apikey'] = $_POST['liga64apikey'];
		$liga64options['liga64referer'] = $_POST['liga64referer'];
		$liga64options['liga64comment'] = $_POST['liga64comment'];
		update_option('liga64_options', $liga64options);
	}
	else {
		$liga64options = get_option('liga64_options');
		if(!isset($liga64options) || $liga64options == false) {
			$liga64options['liga64url'] = 'http://www.liga64.de';
			$liga64options['liga64referer'] = get_option('siteurl');
			$liga64options['liga64comment'] = get_option('blogname');
		}
	}
?>
  <div class="wrap">
    <h2>Einstellungen › Liga64 <a href="<?php echo $requestURI; ?>&requestAPIKey" class="add-new-h2">API-Key beantragen</a></h2>
    <form method="post" action="" novalidate="novalidate">
      <table class="form-table">
        <thead>
        </thead>
        <tfoot>
        </tfoot>
        <tbody>
          <tr>
            <th scope="row">URL:</td>
            <td>
              <input name="liga64url" type="text" id="liga64url" value="<?php echo $liga64options['liga64url']; ?>" class="regular-text">
              <p class="description">Die URL zur Schnittstelle.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Referer:</td>
            <td>
              <input name="liga64referer" type="text" id="liga64referer" value="<?php echo $liga64options['liga64referer']; ?>" class="regular-text">
              <p class="description">Der Referer ist (für gewöhnlich) die URL deiner Seite.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Beschreibung:</td>
            <td>
              <input name="liga64comment" type="text" id="liga64comment" value="<?php echo $liga64options['liga64comment']; ?>" class="regular-text">
              <p class="description">Beschreibe in wenigen Worten oder einem Titel deine Seite.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">API-Key:</td>
            <td>
              <input name="liga64apikey" type="text" id="liga64apikey" value="<?php echo $liga64options['liga64apikey']; ?>" class="regular-text">
              <p class="description">Trage hier deinen API-Key ein oder beantrage einen solchen.</p>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="submit">
        <input type="submit" name="submit" id="submit" class="button button-primary" value="Änderungen übernehmen">
      </p>
    </form>
  </div>
<?php
}
?>
