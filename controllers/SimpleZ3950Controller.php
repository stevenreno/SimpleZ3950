<?php
/* ----------------------------------------------------------------------
 * plugins/statisticsViewer/controllers/StatisticsController.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2010 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__.'/core/TaskQueue.php');
require_once(__CA_LIB_DIR__.'/core/Configuration.php');
require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
require_once(__CA_MODELS_DIR__.'/ca_locales.php');

require_once(__CA_BASE_DIR__."/app/plugins/SimpleZ3950/vendor/pear/file_marc/File/MARC.php");

class SimpleZ3950Controller extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;		// plugin configuration file
	protected $pa_parameters;

	# -------------------------------------------------------
	# Constructor
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

//		if (!$this->request->user->canDoAction('can_use_simple_z3950_plugin')) {
//			$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3000?r='.urlencode($this->request->getFullUrlPath()));
//			return;
//		}

		$this->opo_config = Configuration::load(__CA_APP_DIR__.'/plugins/SimpleZ3950/conf/SimpleZ3950.conf');
					
		// Note : simple import from conf
		// var_dump($this->opo_config->getAssoc('servers'));

	}

	# -------------------------------------------------------
	# Functions to render views
	# -------------------------------------------------------
	public function Index($type="") {
		// GET : $opa_stat=$this->request->getParameter('stat', pString);
		// SET : $this->view->setVar('queryparameters', $opa_queryparameters);
		if(!function_exists("yaz_connect")) {
			$this->view->setVar("message","L'extension PHP Yaz n'est pas disponible sur ce serveur.");
			$this->render('error_html.php');
		} else {
			$servers = $this->opo_config->get("servers");
			$this->view->setVar("servers",$servers);
			$this->render('index_html.php');
		}
	}

	# -------------------------------------------------------
	public function Lot($type="") {
		// GET : $opa_stat=$this->request->getParameter('stat', pString);
		// SET : $this->view->setVar('queryparameters', $opa_queryparameters);
		$this->render('lot_html.php');
	}

	public function Search() {
		$servers = $this->opo_config->get("servers");

		$ps_serveur=$this->request->getParameter('serveur', pString);
		$ps_action=$this->request->getParameter('action', pString);
		$ps_search=$this->request->getParameter('search', pString);
		
		$va_server = $servers[$ps_serveur];

		if(!function_exists("yaz_connect")) {
			$this->view->setVar("message","L'extension PHP Yaz n'est pas disponible sur ce serveur.");
			$this->render('error_html.php');
		} else {
			if($va_server["user"]) {
				$va_yaz_options=["user"=>$va_server["user"],"password"=>$va_server["password"]];	
			} else {
				$va_yaz_options= null;
			}
			
			$vs_zurl=$va_server["url"];
			if (!$va_yaz_options) {
				$vo_yaz_ressource = yaz_connect($vs_zurl);
			} else {
				$vo_yaz_ressource = yaz_connect($vs_zurl, $va_yaz_options);
			}
			yaz_syntax($vo_yaz_ressource, "unimarc");
			yaz_range($vo_yaz_ressource, 1, 10);

			$request = "@attr 1=".$va_server["attribute"]." "." \"". $ps_search . "\"";

			yaz_search($vo_yaz_ressource, "rpn", $request);
			yaz_wait();
	
			$error = yaz_error($vo_yaz_ressource);
	
			if (!empty($error)) {
				echo "Erreur : $error";
			} else {
				$nb_hits = yaz_hits($vo_yaz_ressource);
				//var_dump($nb_hits);
				$files=[];
				$previews = [];
				$raws = [];
				$titles = [];
				$i=1;

				while($i<=$nb_hits) {
					$rec = yaz_record($vo_yaz_ressource, $i, "raw");
					$rec_display = yaz_record($vo_yaz_ressource, $i, "string");
					$rec_array = yaz_record($vo_yaz_ressource, $i, "array");
					if (empty($rec)) continue;
					$target_file = __CA_APP_DIR__."/tmp/z3950_".str_replace([" ",",","!","/","\\","'"],"",$ps_search)."_".$i.".pan";
					$target_file = $this->remove_accents($target_file);
					file_put_contents($target_file, $rec);
					$files[] = $target_file;
					$marc = new File_MARC($rec, File_MARC::SOURCE_STRING);
					$first_record_inside_marc = $marc->next();
					
					$preview = $va_server["preview"];
					preg_match_all("/\^([0-9][0-9][0-9])\/([a-z])/", $va_server["preview"], $matches);

					$fieldcodes = $matches[1];
					$subfieldcodes = $matches[2];
					$matches=$matches[0];

					foreach($matches as $key=>$match) {
						$fields = $first_record_inside_marc->getFields($fieldcodes[$key]);
						foreach($fields as $f) {
							$subf = $f->getSubfield($subfieldcodes[$key]);
							if(is_bool($subf)) {
								$preview = str_replace($match, "", $preview);
							} else {
								$preview = str_replace($match, $subf->getData(), $preview);	
							}
						}
						// No mapping for this code, empty it.
						$preview = str_replace($match, "", $preview);	
					}
					$previews[]= $rec_display;
					
					// Cleanup preview before sending
					$preview = trim($preview, ",");
					$preview = str_replace(" ,",",",$preview);
					$preview = str_replace(",,",",",$preview);
					$titles[] = $preview;
					
					$raws[]= $rec;
					$i++;
				}
			}
			$this->view->setVar("nb_results", $nb_hits);
			$this->view->setVar("files", $files);
			$this->view->setVar("previews", $previews);
			$this->view->setVar("raws", $raws);
			$this->view->setVar("titles", $titles);
			$this->render('search_results_html.php');
		}

	}

	public function Import() {
		$vn_results = $this->request->getParameter('nb_results', pInteger);
		//var_dump($vn_results);
		$files=[];
		$commands=[];
		$outputs=[];
		for($i=0;$i<$vn_results;$i++) {
			$filepath = $this->request->getParameter('file_'.$i, pString);
			if($filepath != "") {
				$files[] = $filepath;
				$command= "cd ".__CA_BASE_DIR__."/support && ./bin/caUtils import-data -s ".$filepath." -m z3950_import_marc -f marc -l . -d DEBUG";
				//print $command;
				//print "Import de l'enregistrement n°".$i."\n<br/>";
				exec($command, $output, $return_var);
				$commands[]=$command;
				$outputs[]=$output;
				//var_dump($output);
				//var_dump($return_var);
				//print $result."<br>\n";
			}
		}
		// ./bin/caUtils import-data -s /www/www.z3950.local/gestion/app/tmp/z3950_2-02-013706-2_1.pan -m z3950_import_marc -f marc -l . -d DEBUG
		$this->view->setVar("outputs",$outputs);
		$this->view->setVar("commands",$commands);
		$this->render('import_html.php');
	}
	# -------------------------------------------------------
	
	public function remove_accents($string) {
    if ( !preg_match('/[\x80-\xff]/', $string) )
        return $string;

    $chars = array(
    // Decompositions for Latin-1 Supplement
    chr(195).chr(128) => 'A', chr(195).chr(129) => 'A',
    chr(195).chr(130) => 'A', chr(195).chr(131) => 'A',
    chr(195).chr(132) => 'A', chr(195).chr(133) => 'A',
    chr(195).chr(135) => 'C', chr(195).chr(136) => 'E',
    chr(195).chr(137) => 'E', chr(195).chr(138) => 'E',
    chr(195).chr(139) => 'E', chr(195).chr(140) => 'I',
    chr(195).chr(141) => 'I', chr(195).chr(142) => 'I',
    chr(195).chr(143) => 'I', chr(195).chr(145) => 'N',
    chr(195).chr(146) => 'O', chr(195).chr(147) => 'O',
    chr(195).chr(148) => 'O', chr(195).chr(149) => 'O',
    chr(195).chr(150) => 'O', chr(195).chr(153) => 'U',
    chr(195).chr(154) => 'U', chr(195).chr(155) => 'U',
    chr(195).chr(156) => 'U', chr(195).chr(157) => 'Y',
    chr(195).chr(159) => 's', chr(195).chr(160) => 'a',
    chr(195).chr(161) => 'a', chr(195).chr(162) => 'a',
    chr(195).chr(163) => 'a', chr(195).chr(164) => 'a',
    chr(195).chr(165) => 'a', chr(195).chr(167) => 'c',
    chr(195).chr(168) => 'e', chr(195).chr(169) => 'e',
    chr(195).chr(170) => 'e', chr(195).chr(171) => 'e',
    chr(195).chr(172) => 'i', chr(195).chr(173) => 'i',
    chr(195).chr(174) => 'i', chr(195).chr(175) => 'i',
    chr(195).chr(177) => 'n', chr(195).chr(178) => 'o',
    chr(195).chr(179) => 'o', chr(195).chr(180) => 'o',
    chr(195).chr(181) => 'o', chr(195).chr(182) => 'o',
    chr(195).chr(182) => 'o', chr(195).chr(185) => 'u',
    chr(195).chr(186) => 'u', chr(195).chr(187) => 'u',
    chr(195).chr(188) => 'u', chr(195).chr(189) => 'y',
    chr(195).chr(191) => 'y',
    // Decompositions for Latin Extended-A
    chr(196).chr(128) => 'A', chr(196).chr(129) => 'a',
    chr(196).chr(130) => 'A', chr(196).chr(131) => 'a',
    chr(196).chr(132) => 'A', chr(196).chr(133) => 'a',
    chr(196).chr(134) => 'C', chr(196).chr(135) => 'c',
    chr(196).chr(136) => 'C', chr(196).chr(137) => 'c',
    chr(196).chr(138) => 'C', chr(196).chr(139) => 'c',
    chr(196).chr(140) => 'C', chr(196).chr(141) => 'c',
    chr(196).chr(142) => 'D', chr(196).chr(143) => 'd',
    chr(196).chr(144) => 'D', chr(196).chr(145) => 'd',
    chr(196).chr(146) => 'E', chr(196).chr(147) => 'e',
    chr(196).chr(148) => 'E', chr(196).chr(149) => 'e',
    chr(196).chr(150) => 'E', chr(196).chr(151) => 'e',
    chr(196).chr(152) => 'E', chr(196).chr(153) => 'e',
    chr(196).chr(154) => 'E', chr(196).chr(155) => 'e',
    chr(196).chr(156) => 'G', chr(196).chr(157) => 'g',
    chr(196).chr(158) => 'G', chr(196).chr(159) => 'g',
    chr(196).chr(160) => 'G', chr(196).chr(161) => 'g',
    chr(196).chr(162) => 'G', chr(196).chr(163) => 'g',
    chr(196).chr(164) => 'H', chr(196).chr(165) => 'h',
    chr(196).chr(166) => 'H', chr(196).chr(167) => 'h',
    chr(196).chr(168) => 'I', chr(196).chr(169) => 'i',
    chr(196).chr(170) => 'I', chr(196).chr(171) => 'i',
    chr(196).chr(172) => 'I', chr(196).chr(173) => 'i',
    chr(196).chr(174) => 'I', chr(196).chr(175) => 'i',
    chr(196).chr(176) => 'I', chr(196).chr(177) => 'i',
    chr(196).chr(178) => 'IJ',chr(196).chr(179) => 'ij',
    chr(196).chr(180) => 'J', chr(196).chr(181) => 'j',
    chr(196).chr(182) => 'K', chr(196).chr(183) => 'k',
    chr(196).chr(184) => 'k', chr(196).chr(185) => 'L',
    chr(196).chr(186) => 'l', chr(196).chr(187) => 'L',
    chr(196).chr(188) => 'l', chr(196).chr(189) => 'L',
    chr(196).chr(190) => 'l', chr(196).chr(191) => 'L',
    chr(197).chr(128) => 'l', chr(197).chr(129) => 'L',
    chr(197).chr(130) => 'l', chr(197).chr(131) => 'N',
    chr(197).chr(132) => 'n', chr(197).chr(133) => 'N',
    chr(197).chr(134) => 'n', chr(197).chr(135) => 'N',
    chr(197).chr(136) => 'n', chr(197).chr(137) => 'N',
    chr(197).chr(138) => 'n', chr(197).chr(139) => 'N',
    chr(197).chr(140) => 'O', chr(197).chr(141) => 'o',
    chr(197).chr(142) => 'O', chr(197).chr(143) => 'o',
    chr(197).chr(144) => 'O', chr(197).chr(145) => 'o',
    chr(197).chr(146) => 'OE',chr(197).chr(147) => 'oe',
    chr(197).chr(148) => 'R',chr(197).chr(149) => 'r',
    chr(197).chr(150) => 'R',chr(197).chr(151) => 'r',
    chr(197).chr(152) => 'R',chr(197).chr(153) => 'r',
    chr(197).chr(154) => 'S',chr(197).chr(155) => 's',
    chr(197).chr(156) => 'S',chr(197).chr(157) => 's',
    chr(197).chr(158) => 'S',chr(197).chr(159) => 's',
    chr(197).chr(160) => 'S', chr(197).chr(161) => 's',
    chr(197).chr(162) => 'T', chr(197).chr(163) => 't',
    chr(197).chr(164) => 'T', chr(197).chr(165) => 't',
    chr(197).chr(166) => 'T', chr(197).chr(167) => 't',
    chr(197).chr(168) => 'U', chr(197).chr(169) => 'u',
    chr(197).chr(170) => 'U', chr(197).chr(171) => 'u',
    chr(197).chr(172) => 'U', chr(197).chr(173) => 'u',
    chr(197).chr(174) => 'U', chr(197).chr(175) => 'u',
    chr(197).chr(176) => 'U', chr(197).chr(177) => 'u',
    chr(197).chr(178) => 'U', chr(197).chr(179) => 'u',
    chr(197).chr(180) => 'W', chr(197).chr(181) => 'w',
    chr(197).chr(182) => 'Y', chr(197).chr(183) => 'y',
    chr(197).chr(184) => 'Y', chr(197).chr(185) => 'Z',
    chr(197).chr(186) => 'z', chr(197).chr(187) => 'Z',
    chr(197).chr(188) => 'z', chr(197).chr(189) => 'Z',
    chr(197).chr(190) => 'z', chr(197).chr(191) => 's'
    );

    $string = strtr($string, $chars);

	    return $string;
	}
}
?>
