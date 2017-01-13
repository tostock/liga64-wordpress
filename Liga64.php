<?php
/*
Plugin Name: Liga64
Plugin URI: http://www.liga64.de/
Description: Das offizielle Plugin von Liga64 um Tabellen auf Wordpress-Seiten einzubinden.
Version: 0.1.0
Author: Tobias Stock <tobias@liga64.de>
Author URI: http://www.liga64.de/
*/

wp_enqueue_script('ChartJS', plugin_dir_url(__FILE__) . 'Chart.min.js');

add_action('admin_menu', 'liga64_admin_actions');

// add shortcode
add_shortcode('liga64_tabelle', 'liga64_tabelle');
add_shortcode('liga64_setzliste', 'liga64_setzliste');
add_shortcode('liga64_wettkampf', 'liga64_wettkampf');
add_shortcode('liga64_diateamchart', 'liga64_diateamchart');
add_shortcode('liga64_diaeinsaetze', 'liga64_diaeinsaetze');
add_shortcode('liga64_diasetzliste', 'liga64_diasetzliste');


add_action( 'wp_ajax_nopriv_liga64Update', 'liga64Update' );
add_action( 'wp_ajax_liga64Update', 'liga64Update' );

class Liga64Constants {
	const ShorcodePrefix = 'liga64_';
	const Tabelle = 'liga64_tabelle';
	const Setzliste = 'liga64_setzliste';
	const Wettkampf = 'liga64_wettkampf';
	const All = 'all';
}

class Liga64Store {
	public $code;
	public $id;
	public $tag;
	public $value;
}

function liga64_getRGBACode($i, $o = '0.5') {
	$rgba = array();
	$rgba[] = 'rgba(46, 204, 113, '.$o.')';
	$rgba[] = 'rgba(52, 152, 219, '.$o.')';
	$rgba[] = 'rgba(149, 165, 166, '.$o.')';
	$rgba[] = 'rgba(155, 89, 182, '.$o.')';
	$rgba[] = 'rgba(241, 196, 15, '.$o.')';
	$rgba[] = 'rgba(231, 76, 60, '.$o.')';
	$rgba[] = 'rgba(52, 73, 94, '.$o.')';
	$rgba[] = 'rgba(255, 69, 0, '.$o.')';
	$rgba[] = 'rgba(255, 165, 0, '.$o.')';
	$rgba[] = 'rgba(218, 112, 214, '.$o.')';

	return $rgba[$i];
}

function liga64_getHexCode($i) {
	$hex = array();
	$hex[] = '#2ecc71';
	$hex[] = '#3498db';
	$hex[] = '#95a5a6';
	$hex[] = '#9b59b6';
	$hex[] = '#f1c40f';
	$hex[] = '#e74c3c';
	$hex[] = '#34495e';
	$hex[] = '#ff4500';
	$hex[] = '#ffa500';
	$hex[] = '#da70d6';

}

function liga64_cmp($a, $b) {
	if($a['einsatz'] > $b['einsatz'])
		return -1;
	elseif($a['einsatz'] < $b['einsatz'])
		return 1;
	else {
		if($a['name'] > $b['name'])
			return -1;
		elseif($a['name'] < $b['name'])
			return 1;
		else {
			return 0;
		}
	}
}

function liga64_startsWith($haystack, $needle) {
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
}

function liga64_endsWith($haystack, $needle) {
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }

    return (substr($haystack, -$length) === $needle);
}

function liga64Update() {
	$requestOK = true;
	$exitMessage = '';
	
	//$liga64store = get_option('liga64_ligastore');
	if(!isset($ligastore)) {

		$liga64store = array();
	}

	$shortcodeprefix = Liga64Constants::ShorcodePrefix;

	ob_start();

	extract( shortcode_atts( array(
			'find' => '',
		), $shortcodeprefix ) );

	$string = $atts['find'];
	$args = array('s' => $string, 'post_type' => array('post', 'page'));

	$the_query = new WP_Query( $args );

	$shortCodeRequests = array();
	$allRequests = array();

	// finde alle Shortcodes, welche mit Liga64 in Verbindung stehen.

	$posts = $the_query->posts;
	for($i = 0; $i < count($posts); $i++) {
		$postContent = $posts[$i]->post_content;
		if (preg_match_all('/.*(\['.$shortcodeprefix.'.*\]).*/', $postContent, $match)) {

			for($j = 0; $j < count($match[1]); $j++) {
					if(preg_match('/\[(.*?)[\s]/', $match[1][$j], $shortcodename)) {
						preg_match('/\[.*id=(\d*).*/', $match[1][$j], $id);
						preg_match('/\[.*tag=(\d*).*/', $match[1][$j], $tag);
						$shortcodename[1] = Liga64Constants::All;
						if(!in_array($id[1], $allRequests)) {
							$shortCodeRequests[] = array('code' => $shortcodename[1], 'id' => $id[1], 'tag' => $tag[1]);
							$allRequests[] = $id[1];
						}
					}
					elseif(preg_match('/\[(.*?)[\]]/', $match[1][$j], $shortcodename)) {
						$shortcodename[1] = Liga64Constants::All;
						if(!in_array($id[1], $allRequests)) {
							$shortCodeRequests[] = array('code' => $shortcodename[1]);
							$allRequests[] = $id[1];
						}
					}
					preg_match('/\[(.*)[\s]/', $match[1][$j], $shortcodename);
			}
		}
	}

	// starte Abfrage an Liga64
	$ids = array();
	try {
		for($i = 0; $i < count($shortCodeRequests); $i++) {
			$res = null;
			switch(trim($shortCodeRequests[$i]['code'])) {
				case Liga64Constants::Tabelle :
					$res = liga64_requestTabelle($shortCodeRequests[$i]['id'], $shortCodeRequests[$i]['tag']);
				break;

				case Liga64Constants::Setzliste :
					$res = liga64_requestSetzliste($shortCodeRequests[$i]['id'], $shortCodeRequests[$i]['tag']);
				break;

				case Liga64Constants::Wettkampf :
					$res = liga64_requestWettkampf($shortCodeRequests[$i]['id'], $shortCodeRequests[$i]['tag']);
				break;

				default:
					$res = liga64_requestAll($shortCodeRequests[$i]['id'], $shortCodeRequests[$i]['tag']);
				break;
			}

			if(isset($res)) {
				$ls = new Liga64Store();
				$ls->code = $shortCodeRequests[$i]['code'];
				$ls->id = $shortCodeRequests[$i]['id'];
				$ls->tag = $shortCodeRequests[$i]['tag'];
				$ls->value = $res;

				if(!in_array($ls->id, $ids)) {
					$liga64store[] = $ls;
					$ids[] = $ls->id;
				}
			}
		}
	}
	catch(Exception $ex) {
		echo "ERROR!\r\n";
		echo $ex->getMessage();
		$requestOK = false;
		$exitMessage = $ex->getMesssage();
	}




	wp_reset_postdata();
	echo ob_get_clean();


	$option_name = 'liga64_ligastore';
	if(get_option($option_name) !== false) {
		update_option($option_name, $liga64store);
 	}
	else {
		$deprecated = null;
		$autoload = 'no';
		add_option($option_name, $liga64store, $deprecated, $autoload);
	}

	if($requestOK) {
		echo 'Ligen64 Records erfolgreich aktualisiert.';
		exit();
	}
	else
		exit($exitMessage);
}




function liga64_diaeinsaetze($atts) {
	$atts = shortcode_atts(
		array('id' => '',
					'breite' => '',
					'zuletzt' => '',
					'tag' => '',
					'filter' => ''
		), $atts);


	if(isset($atts['breite']) && $atts['breite'] != '') {
		$breite = $atts['breite'];
	}
	else {
		$breite = '100%';
	}
	$breiteStyle = $breite;

	$liga64store = get_option('liga64_ligastore');
	$ergebnisDaten = null;
	for($i = 0; $i < count($liga64store); $i++) {
		if(($liga64store[$i]->code == Liga64Constants::All) && $liga64store[$i]->id == $atts['id']) {
			$ergebnisDaten = json_decode($liga64store[$i]->value);
			break;
		}
	}
	$liga = $ergebnisDaten->Ligen[0];
	$setzliste = $liga->Setzliste;
	$mannschaft = null;
	$schuetzen = null;

	for($i = 0; $i < count($setzliste); $i++) {
		if (preg_match('/'.$atts['filter'].'/', $setzliste[$i]->Mannschaft)) {
			$mannschaft = $setzliste[$i]->Mannschaft;
			$schuetzen = $setzliste[$i]->Schuetzen;
		}
	}
	$wettkampftage = count($schuetzen[0]->Ergebnisse);

	$arraySchuetzen = array();
	$arrayEinsatze = array();
	$arrayAssoc = array();
	for($i = 0; $i < count($schuetzen); $i++) {
		$einsatz = 0;

		for($j = 0; $j < $wettkampftage; $j++) {
			$ergebnis = $schuetzen[$i]->Ergebnisse[$j]->Ringe;
			if(isset($ergebnis) && $ergebnis != null) {
				$einsatz++;
			}
		}
		$arraySchuetzen[] = $schuetzen[$i]->Schuetze;
		$arrayEinsatze[] = $einsatz;
		$arrayAssoc[$schuetzen[$i]->Schuetze] = $einsatz;
	}

	arsort($arrayAssoc);
	$arraySchuetzen = array_keys($arrayAssoc);
	$arrayEinsatze = array_values($arrayAssoc);

	ob_start();
	$output = '';
	$output = ob_get_contents();


	$chartName = 'liga64EinsatzChart'.$atts['id'].microtime();
	$output .= '<canvas id="'.$chartName.'" width="'.$breite.'" height="'.$breite.'"></canvas>'."\r\n";
	$output .= '<script>'."\r\n";
	$output .= 'var ctx = document.getElementById("'.$chartName.'");'."\r\n";
	$output .= 'var myChart = new Chart(ctx, {'."\r\n";
	$output .= '	type: \'doughnut\','."\r\n";
	$output .= '	data: {'."\r\n";
	$output .= '		labels: '.json_encode($arraySchuetzen).','."\r\n";
	$output .= '		datasets: [{';
	//$output .= '			label: \''.$atts['filter'].'\',';
	//$output .= '			data: '.json_encode($ergebnisMannschaft).',';
	//$output .= '			backgroundColor: "rgba(153,255,51,0.4)"';
	$output .= '				backgroundColor: ['."\r\n";
	$output .= '					"#2ecc71",'."\r\n";
	$output .= '					"#3498db",'."\r\n";
	$output .= '					"#95a5a6",'."\r\n";
	$output .= '					"#9b59b6",'."\r\n";
	$output .= '					"#f1c40f",'."\r\n";
	$output .= '					"#e74c3c",'."\r\n";
	$output .= '					"#34495e",'."\r\n";
	$output .= '					"#ff4500"'."\r\n";
	//$output .= '					"#ff00ff",'."\r\n";
	$output .= '				],';
	$output .= '				data: '.json_encode($arrayEinsatze)."\r\n";


	$output .= '		}]';
	$output .= '	}';
	$output .= '});';
	$output .= '</script>';

	ob_end_clean();
	return $output;
}

function liga64_diasetzliste($atts) {
	$atts = shortcode_atts(
		array('id' => '',
					'breite' => '',
					'zuletzt' => '',
					'tag' => '',
					'filter' => ''
		), $atts);


	if(isset($atts['breite']) && $atts['breite'] != '') {
		$breite = $atts['breite'];
	}
	else {
		$breite = '100%';
	}
	$breiteStyle = $breite;

	$liga64store = get_option('liga64_ligastore');
	$tabellenDaten = null;
	for($i = 0; $i < count($liga64store); $i++) {
		if(($liga64store[$i]->code == Liga64Constants::Setzliste || $liga64store[$i]->code == Liga64Constants::All) && $liga64store[$i]->id == $atts['id']) {
			$tabellenDaten = json_decode($liga64store[$i]->value);
			break;
		}
	}

	$liga = $tabellenDaten->Ligen[0];
	$setzliste = $liga->Setzliste;
	$mannschaft = null;
	$schuetzen = null;
	// extrahiere Informationen bezüglich Filter aus der Setzliste
	for($i = 0; $i < count($setzliste); $i++) {
		if (preg_match('/'.$atts['filter'].'/', $setzliste[$i]->Mannschaft)) {
			$mannschaft = $setzliste[$i]->Mannschaft;
			$schuetzen = $setzliste[$i]->Schuetzen;
		}
	}

	// Ergebnisliste
	$liste = array();
	for($i = 0; $i < count($schuetzen); $i++) {
		$einsatz = 0;
		$listErgebnis = array();
		foreach($schuetzen[$i]->Ergebnisse as $e) {
			if($e->Ringe != null)
				$einsatz++;
			$listErgebnis[] = $e->Ringe;
		}
		$data = array();
		$data['name'] = $schuetzen[$i]->Schuetze;
		$data['ergebnis'] = $listErgebnis;
		$data['einsatz'] = $einsatz;
		$liste[] = $data;
	}
	usort($liste, "liga64_cmp");

	ob_start();
	$output = '';
	$output = ob_get_contents();

	$wettkampftage = count($schuetzen[0]->Ergebnisse);

	$wettkampftageLabels = array();
	for($i = 0; $i < $wettkampftage; $i++) {
		$wettkampftageLabels[] = ($i + 1);
	}

	$chartName = 'liga64SetzlisteChart'.$atts['id'].microtime();
	$output .= '<canvas id="'.$chartName.'" width="500" height="600"></canvas>'."\r\n";
	$output .= '<script>'."\r\n";
	$output .= 'var ctx = document.getElementById("'.$chartName.'");'."\r\n";
	$output .= 'var myChart = new Chart(ctx, {'."\r\n";
	$output .= '	type: \'line\','."\r\n";
	$output .= '	data: {'."\r\n";
	$output .= '		labels: '.json_encode($wettkampftageLabels).','."\r\n";
	$output .= '		datasets: [{'."\r\n";
	$output .= '			label: \''.$liste[0]['name'].'\','."\r\n";
	$output .= '			data: '.json_encode($liste[0]['ergebnis']).','."\r\n";
	$output .= '			backgroundColor: "'.liga64_getRGBACode(0).'"'."\r\n";
	for($i = 1; $i < count($liste); $i++) {
		$output .= '		}, {'."\r\n";
		$output .= '			label: \''.$liste[$i]['name'].'\','."\r\n";
		$output .= '			data: '.json_encode($liste[$i]['ergebnis']).','."\r\n";
		$output .= '			backgroundColor: "'.liga64_getRGBACode($i).'"'."\r\n";
	}
	$output .= '		}]'."\r\n";
	$output .= '	}'."\r\n";
	$output .= '});'."\r\n";
	$output .= '</script>'."\r\n";

	ob_end_clean();
	return $output;
}

function liga64_diateamchart($atts) {
	$atts = shortcode_atts(
		array('id' => '',
					'breite' => '',
					'zuletzt' => '',
					'tag' => '',
					'filter' => ''
		), $atts);


	if(isset($atts['breite']) && $atts['breite'] != '') {
		$breite = $atts['breite'];
	}
	else {
		$breite = '100%';
	}
	$breiteStyle = $breite;

	$liga64store = get_option('liga64_ligastore');
	$ergebnisDaten = null;
	for($i = 0; $i < count($liga64store); $i++) {
		if(($liga64store[$i]->code == Liga64Constants::All) && $liga64store[$i]->id == $atts['id']) {
			$ergebnisDaten = json_decode($liga64store[$i]->value);
			break;
		}
	}
	$liga = $ergebnisDaten->Ligen[0];
	$wettkaempfe = $liga->Wettkaempfe;

	ob_start();
	$output = '';
	$output = ob_get_contents();

	$begegnungen = array();
	$tage = array();
	$ergebnisMannschaft = array();
	$ergebnisGegner = array();
	for($i = 0; $i < count($wettkaempfe); $i++) {
		if(preg_match('/'.$atts['filter'].'/', $wettkaempfe[$i]->Heimmannschaft->Name) || preg_match('/'.$atts['filter'].'/', $wettkaempfe[$i]->Gastmannschaft->Name)) {
			$tag = $wettkaempfe[$i]->Wettkampftag;
			$begegnung = array($wettkaempfe[$i]->Heimmannschaft->Name.' - ', $wettkaempfe[$i]->Gastmannschaft->Name);


			$ringeMannschaft = 0;
			$ringeGegner = 0;
			if(preg_match('/'.$atts['filter'].'/', $wettkaempfe[$i]->Heimmannschaft->Name)) {
				$ringeMannschaft = $wettkaempfe[$i]->RingeHeim;
				$ringeGegner = $wettkaempfe[$i]->RingeGast;
				//$begegnung = $wettkaempfe[$i]->Gastmannschaft->Name;
			}
			else {
				$ringeMannschaft = $wettkaempfe[$i]->RingeGast;
				$ringeGegner = $wettkaempfe[$i]->RingeHeim;
				//$begegnung = $wettkaempfe[$i]->Heimmannschaft->Name;
			}
			$ergebnisMannschaft[] = $ringeMannschaft;
			$ergebnisGegner[] = $ringeGegner;

			$begegnungen[] = $begegnung;
			$tage[] = $tag;
		}
	}

	$chartName = 'liga64TeamChart'.$atts['id'].microtime();
	$output .= '<canvas id="'.$chartName.'" width="500" height="600"></canvas>'."\r\n";
	$output .= '<script>'."\r\n";
	$output .= 'var ctx = document.getElementById("'.$chartName.'");'."\r\n";
	$output .= 'var myChart = new Chart(ctx, {'."\r\n";
	$output .= '	type: \'line\','."\r\n";
	$output .= '	data: {'."\r\n";
	$output .= '		labels: '.json_encode($begegnungen).','."\r\n";
	$output .= '		datasets: [{';
	$output .= '			label: \''.$atts['filter'].'\',';
	$output .= '			data: '.json_encode($ergebnisMannschaft).',';
	//$output .= '			backgroundColor: "rgba(153,255,51,0.4)"';
	$output .= '			backgroundColor: "rgba(46,204,113,0.5)"';
	$output .= '		}, {';
		$output .= '			label: \'Gegner\',';
		$output .= '			data: '.json_encode($ergebnisGegner).',';
		//$output .= '			backgroundColor: "rgba(255,153,0,0.4)"';
		$output .= '			backgroundColor: "rgba(231,76,60,0.5)"';
	$output .= '		}]';
	$output .= '	}';
	$output .= '});';
	$output .= '</script>';

	ob_end_clean();
	return $output;
}

function liga64_wettkampf($atts) {
	$atts = shortcode_atts(
		array('id' => '',
					'breite' => '',
					'zuletzt' => '',
					'tag' => '',
					'filter' => ''
		), $atts);

	if(isset($atts['breite']) && $atts['breite'] != '') {
		$breite = $atts['breite'];
	}
	else {
		$breite = '100%';
	}
	$breiteStyle = $breite;

	$liga64store = get_option('liga64_ligastore');
	$ergebnisDaten = null;
	for($i = 0; $i < count($liga64store); $i++) {
		if(($liga64store[$i]->code == Liga64Constants::Wettkampf || $liga64store[$i]->code == Liga64Constants::All) && $liga64store[$i]->id == $atts['id']) {
			$ergebnisDaten = json_decode($liga64store[$i]->value);
			break;
		}
	}
	$liga = $ergebnisDaten->Ligen[0];
	$wettkaempfe = $liga->Wettkaempfe;
	$wettkampf = null;
	for($i = count($wettkaempfe); $i > 0; $i--) {
		if (preg_match('/'.$atts['filter'].'/', $wettkaempfe[$i]->Heimmannschaft->Name) || preg_match('/'.$atts['filter'].'/', $wettkaempfe[$i]->Gastmannschaft->Name)) {
			$wettkampf = $wettkaempfe[$i];
		}
	}

	ob_start();
	$output = '';
	$output = ob_get_contents();

	$schuetzenanzahl = count($wettkampf->Heimmannschaft->Schuetzen);
	if($schuetzenanzahl < count($wettkampf->Gastmannschaft->Schuetzen))
		$schuetzenanzahl = count($wettkampf->Gastmannschaft->Schuetzen);


	$tabelle  = '';
	if($breiteStyle != '100%') {
		$tabelle .= '<table class="liga64-setzliste table table-bordered table-hover" width="'.$breite.'" border="1" cellpadding="5" cellspacing="0" style="width: '.$breite.';">';
	}
	else {
		$tabelle .= '<table class="liga64-setzliste table table-bordered table-hover" width="'.$breite.'" border="1" cellpadding="5" cellspacing="0">';
	}
	$tabelle .= "  <tbody>\r\n";
	$tabelle .= "    <tr>\r\n";
	$colspan = 2;
	if(isset($liga->Duell) && $liga->Duell)
		$colspan++;
	$tabelle .= '      <th colspan="'.$colspan.'">'.$wettkampf->Heimmannschaft->Name.'</th>'."\r\n";
	$tabelle .= '      <th colspan="'.$colspan.'">'.$wettkampf->Gastmannschaft->Name.'</th>'."\r\n";
	$tabelle .= "    </tr>\r\n";

	$duellHeim = (int)$wettkampf->Heimmannschaft->Duellpunkte;
	$duellGast = (int)$wettkampf->Gastmannschaft->Duellpunkte;
	for($sa = 0; $sa < $schuetzenanzahl; $sa++) {
		$duellHeim += $wettkampf->Heimmannschaft->Schuetzen[$sa]->Ergebnis->Punkte;
		$duellGast += $wettkampf->Gastmannschaft->Schuetzen[$sa]->Ergebnis->Punkte;

		$tabelle .= "    <tr>\r\n";
		$tabelle .= '      <td>'.$wettkampf->Heimmannschaft->Schuetzen[$sa]->Vorname.' '.$wettkampf->Heimmannschaft->Schuetzen[$sa]->Nachname.'</td>'."\r\n";
		$tabelle .= '      <td>'.$wettkampf->Heimmannschaft->Schuetzen[$sa]->Ergebnis->Ringe.'</td>'."\r\n";
		if(isset($liga->Duell) && $liga->Duell)
			$tabelle .= '      <td>'.$wettkampf->Heimmannschaft->Schuetzen[$sa]->Ergebnis->Punkte.'</td>'."\r\n";
		$tabelle .= '      <td>'.$wettkampf->Gastmannschaft->Schuetzen[$sa]->Vorname.' '.$wettkampf->Gastmannschaft->Schuetzen[$sa]->Nachname.'</td>'."\r\n";
		$tabelle .= '      <td>'.$wettkampf->Gastmannschaft->Schuetzen[$sa]->Ergebnis->Ringe.'</td>'."\r\n";
		if(isset($liga->Duell) && $liga->Duell)
			$tabelle .= '      <td>'.$wettkampf->Gastmannschaft->Schuetzen[$sa]->Ergebnis->Punkte.'</td>'."\r\n";

		$tabelle .= "    </tr>\r\n";
	}

	$tabelle .= "    <tr>\r\n";
	$tabelle .= '      <td>Gesamt</td>'."\r\n";
	$tabelle .= '      <td>'.$wettkampf->RingeHeim.'</td>'."\r\n";
	if(isset($liga->Duell) && $liga->Duell)
		$tabelle .= '      <td>'.$duellHeim.'</td>'."\r\n";
	$tabelle .= '      <td>Gesamt</td>'."\r\n";
	$tabelle .= '      <td>'.$wettkampf->RingeGast.'</td>'."\r\n";
	if(isset($liga->Duell) && $liga->Duell)
		$tabelle .= '      <td>'.$duellGast.'</td>'."\r\n";
	$tabelle .= "    </tr>\r\n";
	$tabelle .= '  </tbody>';
	$tabelle .= '</table>';


	$output .= $tabelle;
	ob_end_clean();
	return $output;

}

function liga64_setzliste($atts) {
	$atts = shortcode_atts(
		array('id' => '',
					'breite' => '',
					'zuletzt' => '',
					'tag' => '',
					'filter' => ''
		), $atts);

	if(isset($atts['breite']) && $atts['breite'] != '') {
		$breite = $atts['breite'];
	}
	else {
		$breite = '100%';
	}
	$breiteStyle = $breite;

	//$tabellenDaten = liga64_requestTabelle($atts['id'], $atts['tag']);
	//$tabellenDaten = json_decode($tabellenDaten);
	$liga64store = get_option('liga64_ligastore');
	$tabellenDaten = null;
	for($i = 0; $i < count($liga64store); $i++) {
		if(($liga64store[$i]->code == Liga64Constants::Setzliste || $liga64store[$i]->code == Liga64Constants::All) && $liga64store[$i]->id == $atts['id']) {
			$tabellenDaten = json_decode($liga64store[$i]->value);
			break;
		}
	}

	$liga = $tabellenDaten->Ligen[0];
	$setzliste = $liga->Setzliste;
	$mannschaft = null;
	$schuetzen = null;
	for($i = 0; $i < count($setzliste); $i++) {
		if (preg_match('/'.$atts['filter'].'/', $setzliste[$i]->Mannschaft)) {
			$mannschaft = $setzliste[$i]->Mannschaft;
			$schuetzen = $setzliste[$i]->Schuetzen;
		}
	}

	ob_start();
	$output = '';
	//print_r($atts);
	$output = ob_get_contents();

	$wettkampftage = count($schuetzen[0]->Ergebnisse);

	$tabelle  = '';
	if($breiteStyle != '100%') {
		$tabelle .= '<table class="liga64-setzliste table table-bordered table-hover" width="'.$breite.'" border="1" cellpadding="5" cellspacing="0" style="width: '.$breite.';">';
	}
	else {
		$tabelle .= '<table class="liga64-setzliste table table-bordered table-hover" width="'.$breite.'" border="1" cellpadding="5" cellspacing="0">';
	}
	$tabelle .= "  <tbody>\r\n";
	$tabelle .= "    <tr>\r\n";
	$tabelle .= "      <th>Name</th>\r\n";
	for($i = 0; $i < $wettkampftage; $i++) {
		$tabelle .= '      <th>'.($i + 1).'</th>'."\r\n";
	}
	$tabelle .= "      <th>Schnitt</th>\r\n";
	$tabelle .= "      <th>Einsätze</th>\r\n";
	$tabelle .= "    </tr>\r\n";
	for($i = 0; $i < count($schuetzen); $i++) {
		$tabelle .= "    <tr>\r\n";
		$tabelle .= '      <td>'.$schuetzen[$i]->Schuetze.'</td>'."\r\n";
		$einsatz = 0;
		for($j = 0; $j < $wettkampftage; $j++) {
			$ergebnis = $schuetzen[$i]->Ergebnisse[$j]->Ringe;
			if(!isset($ergebnis) || $ergebnis == null)
				$ergebnis = 0;
			else {
				$einsatz++;
			}
			$tabelle .= '      <td>'.$ergebnis.'</td>'."\r\n";
		}
		$tabelle .= '      <td>'.$schuetzen[$i]->Schnitt.'</td>'."\r\n";
		$tabelle .= '      <td>'.$einsatz.'</td>'."\r\n";
		$tabelle .= "    </tr>\r\n";
	}
	$tabelle .= '  </tbody>';
	$tabelle .= '</table>';



	$output .= $tabelle;
	ob_end_clean();
	return $output;
}

function liga64_tabelle($atts) {
	$atts = shortcode_atts(
		array('id' => '',
					'breite' => '',
					'zuletzt' => '',
					'tag' => '',
					'filter' => ''
		), $atts);

	if(isset($atts['breite']) && $atts['breite'] != '') {
		$breite = $atts['breite'];
	}
	else {
		$breite = '100%';
	}
	$breiteStyle = $breite;

	//$tabellenDaten = liga64_requestTabelle($atts['id'], $atts['tag']);
	//$tabellenDaten = json_decode($tabellenDaten);
	$liga64store = get_option('liga64_ligastore');
	$tabellenDaten = null;
	for($i = 0; $i < count($liga64store); $i++) {
		if(($liga64store[$i]->code == Liga64Constants::Tabelle || $liga64store[$i]->code == Liga64Constants::All) && $liga64store[$i]->id == $atts['id'] && $liga64store->tag == $atts['tag']) {
			$tabellenDaten = json_decode($liga64store[$i]->value);
			break;
		}
	}

	$liga = $tabellenDaten->Ligen[0];
	$tabelle = $liga->Tabelle;
	$mannschaften = $tabelle->Mannschaften;

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
	if(isset($liga->Duell) && $liga->Duell)
		$tabelle .= "      <th>Duelle</th>\r\n";
	$tabelle .= "      <th>Ringe</th>\r\n";
	$tabelle .= "    </tr>\r\n";
	//for($i = 0; $i < count($tabellenDaten); $i++) {
	foreach($mannschaften as $obj) {
		if(isset($atts['filter']) && $atts['filter'] != '' && preg_match('/'.$atts['filter'].'/', $obj->Name)) {
			$boldprefix = '<b><strong>';
			$boldsuffix = '</b></strong>';
		}
		else {
			$boldprefix = '';
			$boldsuffix = '';
		}

		$tabelle .= "    <tr>\r\n";
		$tabelle .= '      <td>'.$boldprefix.$obj->Tabellenplatzierung->Rang.$boldsuffix.'</td>'."\r\n";
		$tabelle .= '      <td>'.$boldprefix.$obj->Name.$boldsuffix.'</td>'."\r\n";
		$tabelle .= '      <td>'.$boldprefix.$obj->Tabellenplatzierung->Punkte.':'.$obj->Tabellenplatzierung->VergebenePunkte.$boldsuffix.'</td>'."\r\n";
		if(isset($liga->Duell) && $liga->Duell)
			$tabelle .= '      <td>'.$boldprefix.$obj->Tabellenplatzierung->GewonneneDuelle.':'.$obj->Tabellenplatzierung->VerloreneDuelle.$boldsuffix.'</td>'."\r\n";
		$tabelle .= '      <td>'.$boldprefix.$obj->Tabellenplatzierung->Ringe.':'.$obj->Tabellenplatzierung->VergebeneRinge.$boldsuffix.'</td>'."\r\n";
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

function liga64_isJSONString($str) {
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

function liga64_requestAll($ligaId, $tag) {
	$searchObject = new stdClass();
	$searchObject->Id = (int)$ligaId;
	$searchObject->GetTabelle = true;
	$searchObject->GetKontaktdaten = true;
	$searchObject->GetWettkaempfe = true;
	$searchObject->GetErgebnisse = true;
	$searchObject->GetSetzliste = true;
	if(isset($tag) && $tag != '')
		$searchObject->Tag .= $tag;
	return liga64_request($ligaId, $tag, $searchObject, 'GetLigen');
}

function liga64_requestWettkampf($ligaId, $tag) {
	$searchObject = new stdClass();
	$searchObject->Id = (int)$ligaId;
	$searchObject->GetTabelle = false;
	$searchObject->GetKontaktdaten = false;
	$searchObject->GetWettkaempfe = true;
	$searchObject->GetErgebnisse = true;
	$searchObject->GetSetzliste = false;
	if(isset($tag) && $tag != '')
		$searchObject->Tag .= $tag;
	return liga64_request($ligaId, $tag, $searchObject, 'GetLigen');
}

function liga64_requestSetzliste($ligaId, $tag) {
	$searchObject = new stdClass();
	$searchObject->Id = (int)$ligaId;
	$searchObject->GetTabelle = false;
	$searchObject->GetKontaktdaten = false;
	$searchObject->GetWettkaempfe = false;
	$searchObject->GetErgebnisse = false;
	$searchObject->GetSetzliste = true;
	if(isset($tag) && $tag != '')
		$searchObject->Tag .= $tag;
	return liga64_request($ligaId, $tag, $searchObject, 'GetLigen');
}

function liga64_requestTabelle($ligaId, $tag) {
	$searchObject = new stdClass();
	$searchObject->Id = (int)$ligaId;
	$searchObject->GetTabelle = true;
  $searchObject->GetKontaktdaten = false;
  $searchObject->GetWettkaempfe = false;
  $searchObject->GetErgebnisse = false;
	$searchObject->GetSetzliste = false;
	if(isset($tag) && $tag != '')
		$searchObject->Tag .= $tag;

	return liga64_request($ligaId, $tag, $searchObject, 'GetLigen');
}

function liga64_request($ligaId, $tag, $searchObject = null, $method = null) {
	$liga64options = get_option('liga64_options');

	// use a HTTP POST instead of curl
	$host     = $liga64options['liga64url'];
	$apikey   = $liga64options['liga64apikey'];

	$urlParams = parse_url($host);

	$pfad = 'api/'.$method.'/';
	if(isset($urlParams['path']) && liga64_endsWith($urlParams['path'], '/') == false)
		$pfad = '/'.$pfad;

	$daten = json_encode($searchObject);

	$socket = fsockopen($urlParams['host'], 80, $errno, $errstr);

	$postData  = "POST ".$urlParams['path'].$pfad." HTTP/1.1\r\n";
	$postData .= "Host: ".$urlParams['host']."\r\n";
	$postData .= "Content-Type: application/json; charset=UTF-8\r\n";
	$postData .= "Content-length: ". strlen($daten) ."\r\n";
	$postData .= "Token: ".$apikey."\r\n";
	$postData .= "Connection: close\r\n\r\n";
	$postData .= $daten;
	fputs($socket, $postData);

	$res = "";
	while(!feof($socket)) {
		$res .= fgets($socket, 128);
	}
	fclose($socket);

	if (!$socket) {
		return "$errstr ($errno)<br />\n";
	}

	$returnValue = preg_match('/HTTP\\/\\d.\\d (\\d{3}) (.*)?/', $res, $matches);
	$httpCode = $matches[1];
	$httpMessage = $matches[2];
	if($httpCode != 200) {
		return $httpMessage;
	}

	$matches = array();
	preg_match('^\[.*?\]^', $res, $matches);

	$res = substr($res, strpos($res,"\r\n\r\n")+4);
	$res = explode("\r\n", $res);


	$response = '';
	for($i = 0; $i < count($res); $i++) {
		if(!is_int($res[$i]))
			$response .= $res[$i];
	}
	
	for($i = 0; $i < count($res); $i++) {
		if(liga64_isJSONString($response)) {
			$obj = json_decode($response);
			if($obj->Ok == true) {
				return $response;
			}
			else {
				throw new Exception($obj->Message);
			}
		}
		else {
			throw new Exception("Wrong response format: ".$response);
		}
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
		$apikey 		= liga64_requestAPIKey();
		$liga64options 	= get_option('liga64_options');
		$liga64options['liga64apikey'] = $apikey;
		update_option('liga64_options', $liga64options);
	}

	if(isset($_POST['submit']))
	{
		$liga64url 		= filter_var($_POST['liga64url'], FILTER_SANITIZE_URL);
		$liga64apikey 	= filter_var($_POST['liga64apikey'], FILTER_SANITIZE_STRING);
		$liga64referer 	= filter_var($_POST['liga64referer'], FILTER_SANITIZE_URL);
		$liga64comment	= filter_var($_POST['liga64comment'], FILTER_SANITIZE_STRING);
		
		if(	filter_var($liga64url, FILTER_VALIDATE_URL) &&
			filter_var($liga64referer, FILTER_VALIDATE_URL) &&
			filter_var($liga64apikey, FILTER_VALIDATE_STRING) &&
			filter_var($liga64comment, FILTER_VALIDATE_STRING)) {
	
				$liga64options = array();
				$liga64options['liga64url'] 	= $liga64url;
				$liga64options['liga64apikey'] 	= $liga64apikey;
				$liga64options['liga64referer'] = $liga64referer;
				$liga64options['liga64comment'] = $liga64comment;
				update_option('liga64_options', $liga64options);
				
			}
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
              <input name="liga64url" type="text" id="liga64url" value="<?php echo esc_url($liga64options['liga64url']); ?>" class="regular-text">
              <p class="description">Die URL zur Schnittstelle.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Referer:</td>
            <td>
              <input name="liga64referer" type="text" id="liga64referer" value="<?php echo esc_url($liga64options['liga64referer']); ?>" class="regular-text">
              <p class="description">Der Referer ist (für gewöhnlich) die URL deiner Seite.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Beschreibung:</td>
            <td>
              <input name="liga64comment" type="text" id="liga64comment" value="<?php echo esc_html($liga64options['liga64comment']); ?>" class="regular-text">
              <p class="description">Beschreibe in wenigen Worten oder einem Titel deine Seite.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">API-Key:</td>
            <td>
              <input name="liga64apikey" type="text" id="liga64apikey" value="<?php echo esc_html($liga64options['liga64apikey']); ?>" class="regular-text">
              <p class="description">Trage hier deinen API-Key ein oder beantrage einen solchen.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Chronjob-URL:</td>
            <td>
              <input name="liga64chronjoburl" type="text" id="liga64chronjoburl" value="<?php echo esc_url(admin_url().'admin-ajax.php?action=liga64Update'); ?>" class="regular-text" style="width: 600px;" disabled>
              <p class="description">Diesen Link via Cronjob aufrufen, damit die Daten über die Shortcodes aktualisiert werden.</p>
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
