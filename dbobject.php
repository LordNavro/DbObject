<?php

	/**
	 * Basic interface for creating database table row-based objects 
	 * Pay attention to the fact that any changes made to these objects aren't
	 * written back into the database UNTIL the destructor is called
	 * (for reason of optimization)
	 * Therefore, there might be some inconsistence if more instances represent the same object.
	 * All abstract methods must be implemented correctly in descendant classes.
	 * @author Lord_Navro
	 *
	 */
	abstract class DbObject
	{
		const MODE_NO_ACTION = "noAction";
		const MODE_UPDATE = "update";
		
		/**
		 * This array caches the structre of tables
		 * used to prevent multiple describe table calls
		 * @var multitype
		 */
		static protected $tableStructureCache;
		
		/**
		 * Variable used to cache table structure info
		 * Initialized in constructor
		 * @var array
		 */
		protected $tableStructure;
		
		/**
		 * For each table, this array holds set of columns primary key consists of
		 * @var array
		 */
		protected $primaryColumns;
		
		/**
		 * For each table, this array holds set of columns except the primary ones
		 * @var array
		 */
		protected $columns;
		
		/**
		 * Returns link to the database connection containing desired data
		 * typically Gl::$pdo
		 * @return DbConnection
		 */
		static public function dbConnection(){return Gl::$pdo;}
		
		/**
		 * Name of the tables containing objects
		 * MUST BE MERGED WITH PARENT TABLES IN CHILD CLASSES
		 * Parent tables must be before the child tables in order to hold
		 * foreign key integrity and correct autoincrement values
		 * when inserting new row(s)
		 * @return string
		 */
		static protected function tables(){return array();}
	
		/**
		 * Paths to files to be deleted with object
		 * MUST BE MERGED WITH PARENT FILES IN CHILD CLASSES
		 * gets unlinked when deleting object
		 * @return string
		 */
		protected function relatedFiles(){return array();}
		
		/**
		 * Paths to objects to be deleted with object
		 * MUST BE MERGED WITH PARENT OBJECTS IN CHILD CLASSES
		 * gets deleted when deleting object
		 * @return multitype:DbObject
		 */
		protected function relatedObjects(){return array();}
		
		/**
		 * Returns names of columns which are meant to be multilingual
		 * @return multitype:String
		 */
		static function translations(){return array();}
	
		/**
		 * Defines whether the object was manipulated
		 * for reasons of optimalization 
		 * and write-back by destruction
		 * @var bool
		 */
		protected $dirty = false;
		
		/**
		 * Defines whether the object translations were manipulated
		 * for reasons of optimalization 
		 * and write-back by destruction
		 * @var multitype:multitype:bool
		 */
		protected $dirtyTrans = array();
		
		/**
		 * Defaultly, the object is considered to represent some database entity
		 * and changes will be written back if attributes get changed
		 * to prevent this, use noAction();
		 * @var unknown_type
		 */
		protected  $mode = self::MODE_UPDATE;
		
		/**
		 * Prevents the object to make any changes in the database, 
		 * can be used to emulate not existing entities
		 */
		public function modeNoAction()
		{
			$this->mode = self::MODE_NO_ACTION;
		}
		
		/**
		 * The fetched row in a form of an associative array
		 * @var multitype:mixed
		 */
		protected $row = array();
		
		/**
		 * The fetched translation row in a form of an indexed array
		 * @var multitype:mixed
		 */
		protected $rowTrans = array();
		
		/**
		 * Constructor, initializes the table structure according to the current object
		 * MUST BE CALLED VIA parent::__construct() IN CHILD CONSTRUCTORS
		 */
		public function __construct()
		{
			$this->tableStructure = array();
			$this->primaryColumns = array();
			$this->columns = array();
			foreach(static::tables() as $table)
			{
				if(isset(self::$tableStructureCache[$table]))
				{
					$this->tableStructure = self::$tableStructureCache[$table]["tableStructure"];
					$this->primaryColumns = self::$tableStructureCache[$table]["primaryColumns"];
					$this->columns = self::$tableStructureCache[$table]["columns"];
					continue;
				}
				$this->tableStructure[$table] = array();
				$this->primaryColumns[$table] = array();
				$this->columns[$table] = array();
				$statement = static::dbConnection()->query("DESCRIBE `$table`");
				while($row = $statement->fetch())
				{
					/*if(in_array($row["Field"], $this->translations()))
						continue;*/
					$this->tableStructure[$table][$row["Field"]] = array( 
						"primary" => $row["Key"] == "PRI",
						"type" => $row["Type"],
						"autoIncrement" => $row["Extra"] == "auto_increment");
					if($row["Key"] == "PRI")
						$this->primaryColumns[$table][] = $row["Field"];
					else 
						$this->columns[$table][] = $row["Field"];
				}
				self::$tableStructureCache[$table]["tableStructure"] = $this->tableStructure;
				self::$tableStructureCache[$table]["primaryColumns"] = $this->primaryColumns;
				self::$tableStructureCache[$table]["columns"] = $this->columns;
			}
		}

		/**
		 * Fills the object attributes with specified assoc. array, especially fetched (joined) row
		 * @param array $array
		 */
		public function fromArray($array)
		{
			$this->row = $array;
			$this->rowTrans = array();
		}
		
		/**
		 * Fills the object with desired data
		 * based on the primary key passed
		 * !!!order of the items in the array must correspond to $this->primaryKey();
		 * if no array is given, single-column primary key is considered
		 * @param array $keyArray
		 */
		public function fromPrimary($keyArray)
		{
			$this->row = array();
			$this->rowTrans = array();
			foreach ($this->primaryColumns as $table => $columns) 
			{
				$class = get_called_class();
				$function = function($item)use($keyArray, $class)
				{
					return "`$item` = ".$class::dbConnection()->quote(is_array($keyArray) ? $keyArray[$item] : $keyArray); 
				};
				$where = array_map($function, $columns);
				$where = implode(" AND ", $where);
				
				$statement = $class::dbConnection()->query("SELECT * FROM `$table` WHERE $where");
				$row = $statement->fetch(PDO::FETCH_ASSOC);
				if($row == false)
					throw new Exception("Invalid database selection in DBObject::fromPrimary");
				$this->row = array_merge($this->row, $row);
			}		
		}
	
		/**
		 * Fills the object with desired data
		 * based on some unique set of attributes passed
		 * in an associative column -> value array
		 * @param array $keyArray
		 */
		public function fromUnique($keyArray)
		{
			$this->row = array();
			$this->rowTrans = array();
			foreach ($this->columns as $table => $columns) 
			{
				$where = array();
				foreach($columns as $column) 
				{
					if(isset($keyArray[$column]))
						$where[] = "`$column` = ".static::dbConnection()->quote($keyArray[$column]);
					else if(isset($this->row[$column]))
						$where[] = "`$column` = ".static::dbConnection()->quote($this->row[$column]);
				}
				$where = implode(" AND ", $where);
				$statement = $class::dbConnection()->query("SELECT * FROM `$table` WHERE $where");
				$row = $statement->fetch(PDO::FETCH_ASSOC);
				if($row == false)
					throw new Exception("Invalid database selection in DBObject::fromPrimary");
				$this->row = array_merge($this->row, $row);
			}		
		}
		
		/**
		 * Returns value of stated attribute
		 * @param string $attr
		 * @return mixed
		 */
		public function getAttr($attr)
		{
			return $this->row[$attr];
		}
		
		/**
		 * Sets value of stated attribute to a specified value
		 * @param string $attr
		 * @param mixed $val
		 * @return self
		 */
		public function setAttr($attr, $val) 
		{
			$this->row[$attr] = $val;
			$this->dirty = true;
			return $this;
		}
		
		/**
		 * Returns value for the translation
		 * @param string $languageId
		 * @param string $attr
		 * @return string
		 */
		public function getTrans($languageId, $attr)
		{
			if(isset($this->rowTrans[$languageId][$attr]))
				return $this->rowTrans[$languageId][$attr];
			$statement = static::dbConnection()->query("SELECT `data` 
				FROM `translation_items`
				WHERE `languageId` = '$languageId'
				AND `translationId` = '".$this->getAttr($attr)."'");
			$translation = $statement->fetchColumn();
			return strval($this->rowTrans[$languageId][$attr] = $translation);
		}
		
		/**
		 * Sets the translation value
		 * @param string $languageId
		 * @param string $attr
		 * @param string $value
		 * @return DbObject
		 */
		public function setTrans($languageId, $attr, $value)
		{
			$this->rowTrans[$languageId][$attr] = $value;
			$this->dirtyTrans[$languageId][$attr] = true;
			return $this;
		}
		
		/**
		 * Performs an instant database write-back.
		 * Doesn't check the dirty bit/mode as for this function can be used also
		 * as a kind of "rollback" operation when 2 object instances are created and the other is changed.
		 */
		public function update()
		{
			$this->updateTranslations();
			$this->updateTable();
		}
		
		public function updateTable()
		{
			foreach ($this->tableStructure as $table => $columns) 
			{
				if(count($this->columns[$table]) == 0)
					continue;
				$class = get_called_class();
				$object = $this;
				$function = function($item)use($object, $class)
				{
					if(is_null($object->getAttr($item)))
						return NULL;
					return "`$item` = ".$class::dbConnection()->quote($object->getAttr($item));
				};
				$set = array_map($function, $this->columns[$table]);
				$set = array_filter($set, function($item){return !is_null($item);});
				$set = implode(", ", $set);
				$function = function($item)use($object, $class)
				{
					return "`$item` = ".$class::dbConnection()->quote($object->getAttr($item));
				};
				$where = array_map($function, $this->primaryColumns[$table]);
				$where = implode(' AND ', $where);
				static::dbConnection()->query("UPDATE `$table` SET $set WHERE $where");
			}
			$this->dirty = false;
		}
		
		/**
		 * Updates translations based on changes
		 */
		public function updateTranslations()
		{
			foreach($this->dirtyTrans as $languageId => $attrs)
			{
				foreach($attrs as $attr => $allwaysTrue)
				{
					static::dbConnection()->query("REPLACE INTO `translation_items`
						(`languageId`, `translationId`, `data`)
						VALUES ('$languageId', '".$this->getAttr($attr)."', 
							".static::dbConnection()->quote($this->rowTrans[$languageId][$attr]).")");
				}
			}
			$this->dirtyTrans = array();
		}
		
		/**
		 * Inserts the object along with its translations into the database
		 */
		public function insert()
		{
			$this->insertTranslations();
			$this->insertTable();
			$this->updateTranslations();
		}
		
		
		/**
		 * Inserts newly created object into the database
		 * correctly handles autoincrement dependency if tables are in correct order
		 */
		function insertTable($keyword = "INSERT")
		{
			foreach($this->tableStructure as $table => $columns)
			{
				$ai = false;
				$names = array();
				$values = array();
				foreach($columns as $name => $attributes)
				{
					if($attributes["autoIncrement"])
						$ai = $name;
					else if(is_null($this->getAttr($name)))
						continue;
					else
					{
						$names[] = $name;
						$values[] = $this->getAttr($name);
					}
				}
				$function = function($item)
				{
					return "`$item`";
				};
				$names = array_map($function, $names);
				$names = implode(", ", $names);
				$class = get_called_class();
				$function = function($item)use($class)
				{
					return $class::dbConnection()->quote($item);
				};
				$values = array_map($function, $values);
				$values = implode(", ", $values);
				static::dbConnection()->query("$keyword INTO `$table` ($names) VALUES ($values)");
				if($ai)
					$this->setAttr($ai, static::dbConnection()->lastInsertId());
			}
			$this->dirty = false;
		}
		
		/**
		 * Inserts translations into the table in order to get the FK values
		 */
		public function insertTranslations()
		{
			foreach($this->translations() as $translation)
			{
				static::dbConnection()->query("INSERT INTO `translations` VALUES ()");
				$id = static::dbConnection()->lastInsertId();
				$this->setAttr($translation, $id);
			}
		}
		
		/**
		 * Deletes the object from all tables (in reverse order)
		 * in case foreign keys are not set properly
		 * also unlinks all files provided via $this->linkedFiles()
		 * @return string
		 */
		function delete()
		{
			foreach($this->relatedObjects() as $object)
				$object->delete();
			foreach($this->relatedFiles() as $file) 
			{
				if(file_exists($file))
					unlink($file);
			}
			foreach(array_reverse($this->primaryColumns) as $table => $columns) 
			{
				$class = get_called_class();
				$object = $this;
				$function = function($item)use($class, $object)
				{
					return "`$item` = ".$class::dbConnection()->quote($object->getAttr($item));
				};
				$where = array_map($function, $columns);
				$where = implode(' AND ', $where);
				static::dbConnection()->query("DELETE FROM `$table` WHERE $where");
			}
			foreach($this->translations() as $attr)
			{
				static::dbConnection()->query("DELETE FROM `translation_items`
					WHERE `translationId` = '".$this->getAttr($attr)."'");
				static::dbConnection()->query("DELETE FROM `translations`
					WHERE `translationId` = '".$this->getAttr($attr)."'");
			}
			$this->modeNoAction();
		}
		
		/**
		 * Default destructor
		 * writes back changes made to the object into the database
		 * if the dirty seal was broken
		 */
		function __destruct()
		{
			if($this->dirty && $this->mode = self::MODE_UPDATE)
				$this->updateTable();
			if(count($this->dirtyTrans) && $this->mode = self::MODE_UPDATE)
				$this->updateTranslations();
		}
		
		/**
		 * Serializes the data for the json_encode function
		 * used automatically in php >= 5.4.0, must be called manualy otherwise
		 * part of the JsonSerializable interface
		 */
		function jsonSerialize()
		{
			return $this->row;
		}
	
		/**
		 * Fetches all rows in statements, creates and returns array of row-like filled objects of $classname
		 * @param PDOStatement $statement rows to be fetched
		 * @param string $classname name of the DbObject inheriting class
		 */
		static function fetchObjectArray($statement, $className)
		{
			$objects = array();
			if($statement == false)
				throw new Exception("Invalid statement provided for fetching objects");
			while($row = $statement->fetch())
			{
				$object = new $className();
				$object->fromArray($row);
				$objects[] = $object;
			}
			return $objects;
		}
		
		/**
		 * Fetches all rows in statements, creates and returns array of $fieldName cols
		 * @param PDOStatement $statement rows to be fetched
		 * @param string $classname name of the column
		 */
		static function fetchFieldArray($statement, $fieldName = 0)
		{
			$fields = array();
			if($statement == false)
				throw new Exception("Invalid statement provided for fetching objects");
			while($row = $statement->fetch())
			{
				$fields[] = $row[$fieldName];
			}
			return $fields;
		}
	}