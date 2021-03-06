<?php
// vim: set ts=4 sts=4 sw=4 si noet:

App::import('Vendor', 'Cholesterol.utils');

/** Component to assist querying and generating JSON result set when working
 *  with jqGrid
 *
 *  @author Rachman Chavik
 *  @license MIT
 */
class JqgridComponent extends Component {

	public $controller;

	static $mapOpers = array(
			'eq' => '',
			'ne' => ' <>',
			'lt' => ' <',
			'le' => ' <=',
			'gt' => ' >',
			'ge' => ' >=',
			'bw' => ' LIKE',
			'bn' => ' NOT LIKE',
			'in' => '',
			'ni' => ' NOT',
			'ew' => ' LIKE',
			'en' => ' NOT LIKE',
			'cn' => ' LIKE',
			'nc' => ' NOT LIKE'
			);

	public function __construct(ComponentCollection $collection, $settings = array()) {
		parent::__construct($collection, $settings);
	}

	public function initialize(Controller $controller) {
		$this->controller = $controller;
	}

	protected function _extractFields($fields) {
		for ($i = 0; $i < count($fields); $i++) {
			#XXX HACK EXTRACT FIELDS
			if(strpos($fields[$i]," as ")){
				$arr = array(0,$fields[$i]);
			}else
				$arr = explode('.', $fields[$i]);
			$res[$arr[0]][] = $arr[1];
		}
		return $res;
	}

	/** construct $conditions array when using Filter Toolbar feature */
	protected function _mergeFilterConditions(&$conditions, $needFields, $filterMode) {
		$ignoreList = array('ext', 'url', '_search', 'nd', 'page', 'rows', 'sidx', 'sord', 'doExport', 'exportOptions', 'filterMode', 'filters', 'gridId',);
		$url = $this->controller->request->query;
		$i = 0;
		foreach ($url as $key => $val) {
			if ($i == 0) {
				$i++; continue;
			}
			if (in_array($key, $ignoreList))  {
				continue;
			}

			// XXX: convert back _ to . when appropriate
			// TODO: check against $needFields
			$newkey = $key;
			if (strstr($key, '_')) {
				$newkey = preg_replace('/_/', '.', $key, 1);
			}
			#XXX : HACK 0 == exact valid. exact == predefine variable
			if ($filterMode==='exact') {
			//case 'exact':
				$conditions[$newkey] = $val;
			//	break;
			}else{
			//default:
				if(is_null($val)){
					//$conditions[$newkey] = explode(",",$val);
				}else
				if($val=="null"){
					//$conditions[$newkey] = explode(",",$val);
				}else
				if(strpos($val, ',')){
					$conditions[$newkey] = explode(",",$val);
				}else
				if (strpos($val, '--')) {
					
					$date = explode('--', $val);
					if (count($date) == 2) {
						$conditions[$newkey . ' BETWEEN ? AND ?'] = array($date[0], $date[1]);
					} else {
						$conditions[$newkey . ' like'] = '%' . $val . '%';
					}
				} else {
					$conditions[$newkey . ' like'] = '%' . $val . '%';
				}
				//break;
			}
		}
	}

	/** construct $conditions array when using Advanced Search feature */
	protected function _mergeAdvSearchConditions(&$conditions, $needFields, $filters,$fields) {


		
		$rules = array();

		foreach ($filters->rules as $rule) {
				
			$op = JqgridComponent::$mapOpers[$rule->op];
			
			$data = $rule->data;
			#hack untuk memasukan advance select yang hasilnya 0.name menjadi ke kondition, untuk qgrid toolbar search nya di add  defaultSearch : 'nc' sebagai option
			$this->_hack_count_added_condition_to_search($fields,$rule);

			switch ($rule->op) {
			case 'bn':
			case 'bw':
				$data = $data . '%';
				break;
			case 'ew':
			case 'en':
				$data = '%' . $data;
				break;
			case 'cn':
			case 'nc':
				$data = '%' . $data . '%';
				break;

			case 'ni':
				$data = strpos($data, ',') !== false ? explode(',', $data) : $data;
				$data = array_map('trim', $data);
				$op = is_string($data) ? ' <>' : $op;
				break;

			case 'in':
				$data = strpos($data, ',') !== false ? explode(',', $data) : $data;
				$data = array_map('trim', $data);
				$op = is_string($data) ? '' : $op;
				break;
			}

			$rules[]["{$rule->field}{$op}"] = $data;
		}

		$conditions[$filters->groupOp] =& $rules;
	}

	/** Export grid data to CSV */
	protected function _exportToCSV($modelName, $fields, $rows, $exportOptions) {
		$download_filename = $exportOptions['filename'];
		header('Content-Type: application/vnd.ms-excel');
		header('Content-Disposition: attachment; filename='. urlencode($download_filename));
		header("Content-Transfer-Encoding: binary\n");

		$rowLen = count($rows);
		$fieldLen = count($fields);
		$hasHeaders = !empty($exportOptions['headers']);

		// construct list of column headers and display it accordingly
		for ($i = 0; $i < $fieldLen; $i++) {
			$dict = explode('.', $fields[$i]);
			$fieldList[] = $dict;
			if ($hasHeaders) {
				echo $exportOptions['headers'][$i] . ',';
			} else {
				echo $dict[1] . ',';
			}
		}
		echo "\r\n";

		for ($i = 0; $i < $rowLen; $i++) {
			$row = $rows[$i];
			for ($j = 0; $j < $fieldLen; $j++) {
				$dict =& $fieldList[$j];
				if (isset($row[$dict[0]][$dict[1]])) {
					echo $row[$dict[0]][$dict[1]] . ',';
				} else {
					echo ',';
				}
			}
			echo "\r\n";
		}
		Configure::write('debug', 0);
	}

	protected function _exportToXls($modelName, $fields, $rows, $exportOptions) {
		if (!property_exists($this->controller, 'ExcelExporter')) {
			$this->log('Jqgrid requires ExcelExporter component');
		}

		$download_filename = $exportOptions['filename'];
		$columnHeaders = $exportOptions['columnHeaders'];
		header('Content-Type: application/vnd.ms-excel');
		header('Content-Disposition: attachment; filename='. urlencode($download_filename));
		header("Content-Transfer-Encoding: binary\n");

		$tempfile = tempnam('/tmp', 'CE2X');
		$this->controller->ExcelExporter->export($modelName, $rows, array(
			'fields' => $fields,
			'columnHeaders' => $columnHeaders,
			'output' => array(
				'file' => $tempfile,
				)
			)
		);
		Configure::write('debug', 0);
		readfile($tempfile);
		unlink($tempfile);
	}

	protected function _exportToFile($modelName, $fields, $rows, $exportOptions) {
		switch ($exportOptions['type']) {
		case 'csv':
			return $this->_exportToCSV($modelName, $fields, $rows, $exportOptions);
			break;
		case 'xls':
			return $this->_exportToXls($modelName, $fields, $rows, $exportOptions);
			break;
		default:
			$this->log('Unsupported export format');
			break;
		}
	}

	protected function _extractGetParams($url) {
		$page = array_key_value('page', $url);
		$rows = array_key_value('rows', $url);
		$sidx = array_key_value('sidx', $url);
		$sord = array_key_value('sord', $url);
		$_search = (boolean) array_key_value('_search', $url);
		$doExport = (boolean) array_key_value('doExport', $url);
		$filterMode = array_key_value('filterMode', $url);
		$gridId = urldecode(array_key_value('gridId', $url));
		$filters = urldecode(array_key_value('filters', $url));
		$filters = $filters == '' ? null : json_decode($filters);

		return compact('page', 'rows', 'sidx', 'sord', '_search',
			'filters', 'filterMode', 'gridId', 'doExport'
		);
	}

	protected function _getFieldOrder($sidx, $sord) {
		if (!empty($sidx)) {
			$field_order = $sidx . ' ' . $sord;
		} else {
			$field_order = null;
		}
		return $field_order;
	}

	// Ubah rule fieldsnya 
	public function _hack_count_added_condition_to_search(&$fields,&$rule){
			Configure::write('debug', 0);
			foreach($fields as $key =>$value){
				$temp_rule = str_replace("0.", "as ",$rule->field);
				if(strpos($value,$temp_rule)&&strpos("||".$rule->field,"0.")){// || agar start strpos dimulai dari 2 
				//if(strpos($value,$temp_rule)&&strpos($rule->field,"0.")){
					//!separated field and alias for count search condition causing error
					list($field,$alias) = explode("as ",$value);
					$rule->field = $field;
				}
			}	
	
	}
	// Ubah conditions ordernyanya 
	public function _hack_count_change_condition_for_sort(&$fields,&$f){
			
			foreach($fields as $key =>$value){
				$temp_rule = str_replace("0.", " ",$f['sidx']);
				if(strpos($value,$temp_rule)){
				
				//if(strpos($value,$temp_rule)&&strpos($f['sidx'],"0.")){
					return array(
					//	str_replace($temp_rule," ",$value) => $f['sord'],
						$temp_rule => $f['sord']
					); 
				}			
			}	
			return array(
				$f['sidx'] => $f['sord'],
			);
	}
	public function find($modelName, $options = array()) {

		$options += Set::merge(array(
			'conditions' => array(),
			'recursive' => -1,
			'fields' => array()
			), $options);

		extract($options);
		if ($this->controller->request->isPost()) {
			extract($this->_extractGetParams($this->controller->request->params['form']));
		} else {
			$f = $this->_extractGetParams($this->controller->request->query);
			extract($f);
		}
		// hack date from d/m/y to Y/m/d
		$i=0;
		if ($_search) {
			if (!empty($filters)) {
				foreach ($filters->rules as $value) {
					$date = explode("/", $value->data);
					if (count($date)>2) {
						$date_format = 'm/d/y';
						$date = $date[1]."/".$date[0]."/".$date[2];
						$input = trim($date);
						$time = strtotime($input);

						$is_valid = date($date_format, $time) == $input;
						if ($is_valid) {
							$date = DateTime::createFromFormat('d/m/y', $value->data);
							$tgl = $date->format('Y-m-d');
							$filters->rules[$i]->data=$tgl;
						}
					}
					$i++;
				}
			}
		}
		//echo "<pre>";
		//print_r($filters);
		//end hack
		$exportOptions = json_decode(Cache::read('export_options_' . $gridId), true);

		/****
		start hack merge order with sort
		*/
		if(!empty($f['sidx'])){
			$options['order'] = $this->_hack_count_change_condition_for_sort($fields,$f);
		}
		#end hack 
		
		$limit = $rows == 0 ? 10 : $rows;
		$field_order = isset($order) ? $order : $this->_getFieldOrder($sidx, $sord);
		
		$model = ClassRegistry::init($modelName);

		if (!empty($fields)) {
			// user has specified wanted fields, so use it.
			$needFields = $this->_extractFields($fields);
		} else {
			// fallback using model schema fields
			$needFields = array($modelName => array_keys($model->schema()));

			for ($i = 0, $ii = count($needFields[$modelName]); $i < $ii; $i++) {
				$fields[] = $modelName . '.' . $needFields[$modelName][$i];
			}
		}
		if ($_search) {
			if (!empty($filters)) {
				$this->_mergeAdvSearchConditions($options['conditions'], $needFields, $filters,$options['fields']);
			} else {
				$this->_mergeFilterConditions($options['conditions'], $needFields, $filterMode);
			}
		}
		
		$countOptions = $options;
		//	echo "<pre>";
		//print_r($options);

		unset ($countOptions['fields']);
		$count = $model->find('count', $countOptions);

		if ($doExport && $exportOptions) {
			if (in_array(strtolower($exportOptions['type']), array('xls','csv'))) {
				$page = 1;
				$limit = 65535;
			}
			$this->controller->autoRender = false;
		} else {
			$this->controller->viewClass = 'Json';
		}

		$findOptions = $options + array(
			'recursive' => $recursive,
			'page' => $page,
			'limit' => $limit,
			'order' => $field_order
			);
		$rows = $model->find('all', $findOptions);
		//echo "<pre>";
		//print_r($options);
		if ($doExport) {
			if (!empty($rows[0])) {
				$exportFields = array_keys(Set::flatten($rows[0]));
			} else {
				$exportFields = $fields;
			}
			return $this->_exportToFile($modelName, $exportFields, $rows, $exportOptions);
		}

		$total_pages = $count > 0 ? ceil($count/$limit) : 0;
		$row_count = count($rows);

		$response = array(
			'page' => $page,
			'records' => $row_count,
			'total' => $total_pages
			);

		$response += $this->_constructResponse($rows);

		$this->controller->set(compact('response'));
		$this->controller->set('_serialize', 'response');
	}

	protected function _constructResponse($rows) {
		$response = array();
		for ($i = 0, $row_count = count($rows); $i < $row_count; $i++) {
			$row =& $rows[$i];
			$response['rows'][$i] = Set::flatten($row);
		}
		return $response;
	}
}

?>
