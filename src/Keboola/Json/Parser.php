<?php

namespace Keboola\Json;

use Keboola\CsvTable\Table;
use Keboola\Temp\Temp;
use Monolog\Logger;
use Keboola\Json\Exception\JsonParserException as Exception;

/**
 * @TODO Ensure the column&table name don't exceed MySQL limits
 */
class Parser {
	protected $struct;
	protected $headers = array();
	protected $csvFiles = array();
	protected $analyzed;
	protected $rowsAnalyzed = array();
	/**
	 * @var int
	 * Use -1 to always analyze all data
	 */
	protected $analyzeRows;
	/** @var Cache */
	protected $cache;
	/** @var \Monolog\Logger */
	protected $log;
	/** @var Temp */
	protected $temp;

	public function __construct(Logger $logger, array $struct = array(), $analyzeRows = 500)
	{
		$this->struct = $struct;
		$this->analyzeRows = $analyzeRows;

		$this->log = $logger;
	}

	/**
	 * @brief Parse an array of results. If their structure isn't known, it is analyzed and parsed upon retrieval by getCsvFiles()
	 * @TODO FIXME keep the order of data as on the input - try to parse data from Cache before parsing new data
	 *
	 * @param array $data
	 * @param string $type
	 * @param string $parentId
	 *
	 * @return void
	 */
	public function process(array $data, $type = "root", $parentId = null)
	{
		// If we don't know the data (enough), store it in Cache, analyze, and parse when asked for it in getCsvFiles()
		if (empty($data) && empty($this->struct[$type])) { // the analyzer wouldn't set the $struct and parse fails!
			$e = new Exception(500, "Empty data set received for {$type}");
			$e->setData(array(
				"data" => $data,
				"type" => $type,
				"parentId" => $parentId
			));
			throw $e;
		}

		if (
			!array_key_exists($type, $this->struct) ||
			$this->analyzeRows == -1 ||
			(!empty($this->rowsAnalyzed[$type]) && $this->rowsAnalyzed[$type] < $this->analyzeRows)
		) {
			if (empty($this->rowsAnalyzed[$type])) {
				$this->log->log("debug", "analyzing {$type}", array(
					"struct" => json_encode($this->struct),
					"analyzeRows" => $this->analyzeRows,
					"rowsAnalyzed" => json_encode($this->rowsAnalyzed)
				));
			}

			$this->rowsAnalyzed[$type] = empty($this->rowsAnalyzed[$type])
				? count($data)
				: ($this->rowsAnalyzed[$type] + count($data));

			if (empty($this->cache)) {
				$this->cache = new Cache();
			}

			$this->cache->store(array(
				"data" => $data,
				"type" => $type,
				"parentId" => $parentId
			));

			$this->analyze($data, $type);
		} else {
			$this->parse($data, $type, $parentId);
		}
	}

	public function getHeader($type, $parent = false)
	{
		$header = array();
		if (is_scalar($this->struct[$type])) {
			$header[] = "data";
		} else {
			foreach($this->struct[$type] as $column => $dataType) {
				if ($dataType == "object") {
					foreach($this->getHeader($type . "." . $column) as $col => $val) {
						$header[] = $column . "_" . $val;
					}
				} else {
					$header[] = $column;
				}
			}
		}

		if ($parent) {
			if (is_array($parent)) {
				$header = array_merge($header, array_keys($parent));
			} else {
				$header[] = "JSON_parentId"; // TODO allow rename on root level/all levels separately - allow the parent to be an array of "parentColName" => id?
			}
		}

		// TODO set $this->headerNames[$type] = array_combine($validatedHeader, $header); & add a getHeaderNames fn()
		return $this->validateHeader($header);
	}

	protected function validateHeader(array $header)
	{
		$newHeader = array();
		foreach($header as $key => $colName) {
			$newName = $this->createSafeSapiName($colName);

			// prevent duplicates
			if (in_array($newName, $newHeader)) {
				$newHeader[$key] = md5($colName);
			} else {
				$newHeader[$key] = $newName;
			}
		}
		return $newHeader;
	}

	protected function createSafeSapiName($name)
	{
		if (strlen($name) > 64) {
			if(str_word_count($name) > 1 && preg_match_all('/\b(\w)/', $name, $m)) {
				$short = implode('',$m[1]);
			} else {
				$short = md5($name);
			}
			$short .= "_";
			$remaining = 64 - strlen($short);
			$nextSpace = strpos($name, " ", (strlen($name)-$remaining)) ? : strpos($name, "_", (strlen($name)-$remaining));

			if ($nextSpace !== false) {
				$newName = $short . substr($name, $nextSpace);
			} else {
				$newName = $short;
			}
		} else {
			$newName = $name;
		}

		$newName = preg_replace('/[^A-Za-z0-9-]/', '_', $newName);
		return trim($newName, "_");
	}

	public function parse($data, $type, $parentId = null)
	{
		if (empty($this->struct[$type])) {
			// analyse instead of failing if the data is unknown!
			$this->log->log(
				"debug",
				"Json::parse() ran into an unknown data type {$type} - trying on-the-fly analysis",
				array(
					"data" => $data,
					"type" => $type,
					"parentId" => $parentId
				)
			);

			$this->analyze($data, $type);
		}

		if (empty($this->headers[$type])) {
			$this->headers[$type] = $this->getHeader($type, $parentId);
		}

		$safeType = $this->createSafeSapiName($type);
		if (empty($this->csvFiles[$safeType])) {
			$this->csvFiles[$safeType] = Table::create($safeType, $this->headers[$type], $this->getTemp());
			$this->csvFiles[$safeType]->addAttributes(array("fullDisplayName" => $type));
		}

		foreach($data as $row) {
			$parsed = $this->parseRow($row, $type);
			// ensure no fields are missing in CSV row (required in case an object is null and doesn't generate )
			$csvRow = array_replace(array_fill_keys($this->headers[$type], null), $parsed);
			if (!empty($parentId)) {
				if (is_array($parentId)) {
					$csvRow = array_merge($csvRow, $parentId);
				} else {
					$csvRow["JSON_parentId"] = $parentId;
				}
			}
			$this->csvFiles[$safeType]->writeRow($csvRow);
		}
	}

	public function parseRow($dataRow, $type)
	{
		// in case of non-associative array of strings
		if (is_scalar($dataRow)) {
			return array("data" => $dataRow);
		} elseif ($this->struct[$type] == "NULL") {
			return array("data" => json_encode($dataRow));
		}

		$row = array();
		foreach($this->struct[$type] as $column => $dataType) {
			if (empty($dataRow->{$column})) {
				$row[$column] = null;
				continue;
			}

			switch ($dataType) {
				case "array":
					$row[$column] = $type . "_" . uniqid(); // TODO try to use parent's ID - somehow set it or detect it (not sure if that'd be unique)
					$this->parse($dataRow->{$column}, $type . "." . $column, $row[$column]);
					break;
				case "object":
					foreach($this->parseRow($dataRow->{$column}, $type . "." . $column) as $col => $val) {
						$row[$column . "_" . $col] = $val;
					}
					break;
				default:
					// If a column is an object/array while $struct expects a single column, log an error
					if (is_array($dataRow->{$column}) || is_object($dataRow->{$column})) {
						$jsonColumn = json_encode($dataRow->{$column});
						$realType = gettype($dataRow->{$column});
						$this->log->log(
							"ERROR",
							"Data parse error - unexpected '{$realType}'!",
							array(
								"data" => $jsonColumn,
								"row" => json_encode($dataRow),
								"type" => $realType,
								"expected_type" => $dataType
							)
						);
						$row[$column] = $jsonColumn;
					} else {
						$row[$column] = $dataRow->{$column};
					}
					break;
			}
		}

		return $row;
	}

	public function analyze($data, $type)
	{
		foreach($data as $row) {
			$this->analyzeRow($row, $type);
		}
		$this->analyzed = true;
	}

	public function analyzeRow($row, $type)
	{
		// Analyze the current row
		if (!is_array($row) && !is_object($row)) {
			$struct = gettype($row);
		} else {
			foreach($row as $key => $field) {
				$fieldType = gettype($field);
				if ($fieldType == "object") {
					// Only assign the type if the object isn't empty
					if (get_object_vars($field) == array()) {
						continue;
					}

					$this->analyzeRow($field, $type . "." . $key);
				} elseif ($fieldType == "array") {
					$this->analyze($field, $type . "." . $key);
				}

				$struct[$key] = $fieldType;
			}
		}

		// Save the analysis result
		if (empty($this->struct[$type]) || $this->struct[$type] == "NULL") {
			// if we already know the row's types
			$this->struct[$type] = $struct;
		} elseif ($this->struct[$type] !== $struct) {
			// If the current row doesn't match the known structure
			$diff = array_diff_assoc($struct, $this->struct[$type]);
			// Walk through different fields
			foreach($diff as $diffKey => $diffVal) {
				if (empty($this->struct[$type][$diffKey]) || $this->struct[$type][$diffKey] == "NULL") {
					// Assign if the field is new
					$this->struct[$type][$diffKey] = $struct[$diffKey];
				} elseif ($struct[$diffKey] != "NULL") {
					// If the current field type is NULL, just keep the original, otherwise throw an Exception 'cos of a type mismatch
					$old = json_encode($this->struct[$type][$diffKey]);
					$new = json_encode($struct[$diffKey]);
					throw new Exception(400, "Unhandled type change between (previous){$old} and (new){$new} in {$diffKey}");
				}
			}
		}
	}

	public function getCsvFiles()
	{
		// parse what's in cache before returning results
		if(!empty($this->cache)) {
			while ($batch = $this->cache->getNext()) {
				$this->parse($batch["data"], $batch["type"], $batch["parentId"]);
			}
		}
		return $this->csvFiles;
	}

	public function getStruct()
	{
		return $this->struct;
	}

	public function hasAnalyzed()
	{
		return (bool) $this->analyzed;
	}

	protected function getTemp()
	{
		if(!($this->temp instanceof Temp)) {
			$this->temp = new Temp("ex-parser-data");
		}
		return $this->temp;
	}

	/**
	 * @brief Override the self-initialized Temp
	 * @param \Keboola\Temp\Temp $temp
	 */
	public function setTemp(Temp $temp)
	{
		$this->temp = $temp;
	}
}