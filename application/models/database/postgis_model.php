<?php


/**
 * MapIgniter
 *
 * An open source GeoCMS application
 *
 * @package		MapIgniter
 * @author		Marco Afonso
 * @copyright	Copyright (c) 2012, Marco Afonso
 * @license		dual license, one of two: Apache v2 or GPL
 * @link		http://marcoafonso.com/miwiki/doku.php
 * @since		Version 1.0
 * @filesource
 */

// ------------------------------------------------------------------------

class Postgis_model extends CI_Model {
    
    private $exclude_table = array();
    private $exclude_field = array();
    
    public function __construct() {
        parent::__construct();
        
        $this->load->model('database/database_model');
        $this->exclude_table[] = 'spatial_ref_sys';
        $this->exclude_table[] = 'geometry_columns';
        $this->exclude_field[] = 'gid';
        $this->exclude_field[] = 'last_update';
        $this->exclude_field[] = 'owner';
        $this->exclude_field[] = 'the_geom';
        $this->exclude_field[] = 'wkt';
    }
    
    public function createLayer($layer, $pgplacetype = 'novatabela', $srid = 4326, $type = 'POINT') {
        
        // check for unique $pglayer using $layer
        $exists = $this->database_model->find('pglayer', ' layer_id = ? ', array($layer->id));
        if (count($exists)) throw new Exception ('There is already a postgis table using this layer.');

        $pglayer = $this->database_model->create('pglayer');
        $pglayer->layer = $layer;
        $pglayer->srid = $srid;
        $pglayer->pgplacetype = $pgplacetype;
        $pglayer->type = $type;
        $pglayer->last_update = date('Y-m-d H:i:s');
        return $pglayer;
    }
    
    public function saveLayer($pglayer) {
        if (!empty($bean->last_update)) $pglayer->last_update = date('Y-m-d H:i:s');
        $this->database_model->save($pglayer);
    }
    
    public function loadLayer($id) {
        return $this->database_model->load('pglayer', $id);
    }
    
    public function loadLayerAll() {
        return $this->database_model->find('pglayer', ' true ');
    }
    
    public function deleteLayer($ids) {
        // select dependent tables (should it really remove tables?)
        foreach($ids as $id) {
            $pglayer = $this->loadLayer($id);
            $tables[] = $pglayer->pgplacetype;
        }
        $this->deleteTables($tables);
        // Delete postgis layer
        $this->database_model->delete('pglayer', $ids);
    }
    
    public function createTable($name = 'newtable', $srid = 4326, $type = 'POINT', 
            $attributes = array('title' => 'varchar (80)', 'alias' => 'varchar (32)', 'description' => 'text'))
    {
        $table = array();
        $table['oid'] = null;
        $table['name'] = $name;
        $table['srid'] = $srid;
        $table['type'] = $type;
        $table['attributes'] = array();
        $table['attributes']['gid'] = 'serial PRIMARY KEY';
        $table['attributes'] = array_merge($table['attributes'], $attributes);
        $table['attributes']['last_update'] = 'date';
        $table['attributes']['owner'] = 'varchar (20)';
        return (object) $table;
    }
    
    public function createRecord($table)
    {
        $record = array();
        $record['gid'] = null;
        $record['last_update'] = date('Y-m-d');
        $record['owner'] = '';
        foreach ($table->attributes as $field => $type) {
            $record[$field] = null;
        }
        return $record;
    }
    
    public function attributeTypes() {
        return array(
            'varchar (80)' => 'Title - 80 characters max.',
            'varchar (255)' => 'File path - 255 characters max.',
            'text' => 'Text',
            'integer' => 'Numeric (Integer)',
            'real' => 'Numeric (Real number)',
            'date' => 'Date (YYYY-MM-DD)',
            'timestamp without time zone' => 'Local Time (YYYY-MM-DD HH:SS:MM)'
        );
    }
    
    public function import($table, $data, $fields) {
        $fields = explode(',', $fields);
        foreach ($fields as $field) {
            $field = trim($field);
            $table->$field = $data[$field];
        }
        return $table;
    }
    
    public function saveTable($table)
    {
        // Select user database
        $this->database_model->selectDatabase('userdata');

        // Check if table exists
        $sql = "SELECT * FROM geometry_columns WHERE f_table_schema = 'public' and f_table_name = '{$table->name}' LIMIT 1";
        $exists = $this->database_model->getRow($sql);

        if (empty($exists)) {

            // Prepare fields
            foreach ($table->attributes as $field => $type) $attr_str[] = $field.' '.$type;
            $attr_str = implode (',', $attr_str);
            
            // Create table
            $sql = "CREATE TABLE public.{$table->name} ($attr_str)";
            $result = $this->database_model->exec($sql);
            
            // Add Geometry Column
            $sql = "SELECT AddGeometryColumn ('public', '{$table->name}', 'the_geom', {$table->srid}, '{$table->type}', 2)";
            $result = $this->database_model->exec($sql);
            
        }
        else {
            $sql = "
            UPDATE geometry_columns 
            SET srid = {$table->srid}, type = '{$table->type}'
            WHERE f_table_schema = 'public' and f_table_name = '{$table->name}'";
            $result = $this->database_model->exec($sql);
        }
        
        // Select application database
        $this->database_model->selectDatabase();
    }
    
    public function deleteTables($selected) {
        
        // Do nothing if selection is empty
        if (empty($selected)) return;
        
        // Select user database
        $this->database_model->selectDatabase('userdata');

        foreach ($selected as $tablename) {
            
            $sql = "SELECT DropGeometryColumn ('public', '$tablename', 'the_geom')";
            $result = $this->database_model->exec($sql);
            if ($result) {
                $sql = "DROP TABLE public.".$tablename;
                $result = $this->database_model->exec($sql);
            }

        }

        // Select application database
        $this->database_model->selectDatabase();
    }
    
    public function loadAllTables() {
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Create table
        $sql = "
            SELECT c.oid, c.relname 
            FROM pg_catalog.pg_class c
            LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind IN ('r','') AND n.nspname NOT IN ('pg_catalog', 'pg_toast')
            AND pg_catalog.pg_table_is_visible(c.oid);";
        $result = $this->database_model->getAll($sql);
        $list = array();
        if (!empty($result)) {
            foreach ($result as $item) {
                if (in_array($item['relname'], $this->exclude_table)) continue;
                $table = $this->createTable($item['relname']);
                $table->oid = $item['oid'];
                $sql = "SELECT Find_SRID('public', '{$table->name}', 'the_geom')";
                $result_srid = $this->database_model->getRow($sql);
                $table->srid = $result_srid['find_srid'];
                $sql = "SELECT type as geomtype FROM geometry_columns WHERE f_table_schema = 'public' and f_table_name = '{$table->name}' LIMIT 1";
                $result_type = $this->database_model->getRow($sql);
                $table->type = $result_type['geomtype'];
                $list[] = $table;
            }
        }
        // Select application database
        $this->database_model->selectDatabase();
        
        return $list;
    }
    
    public function loadTable($name) {
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Create table
        $table = $this->createTable($name);
        $sql = "SELECT Find_SRID('public', '{$table->name}', 'the_geom')";
        $result_srid = $this->database_model->getRow($sql);
        $table->srid = $result_srid['find_srid'];
        $sql = "
            SELECT oid, type as geomtype 
            FROM geometry_columns 
            WHERE 
                f_table_schema = 'public' 
                and f_table_name = '{$table->name}'
            LIMIT 1";
        $result_type = $this->database_model->getRow($sql);
        $table->type = $result_type['geomtype'];
        $table->oid = $result_type['oid'];
        
        // Get table Columns
        $sql = "
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_name = '{$table->name}'";
        $result_fields = $this->database_model->getAll($sql);
        $table->attributes = array();
        foreach ($result_fields as $item) {
            if (in_array($item['column_name'], $this->exclude_field)) continue;
            $table->attributes[$item['column_name']] = $item['data_type'];
        }

        // Select application database
        $this->database_model->selectDatabase();
        
        return $table;
    }
    
    public function loadRecords($table, $where = ' true ', $values = array(), $limit = 20) {
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Load Records
        $sql = "
            SELECT *, 
                ST_GeometryType(the_geom) as geomtype,
                ST_AsText(the_geom) as wkt
            FROM public.{$table->name} 
            WHERE $where 
            LIMIT $limit OFFSET 0";
        $result = $this->database_model->getAll($sql, $values);
        
        // Select application database
        $this->database_model->selectDatabase();

        return $result;
    }
    
    public function loadRecordsByRatingId($tableids) {
        // Select user database
        $this->database_model->selectDatabase('userdata');

        $result = array();
        foreach ($tableids as $item) {
            // Get table name
            $attrs = explode('.', $item);
            $table = reset($attrs);
            
            // Get id
            $id = end($attrs);
            $where = ' gid = ? ';
            $values[] = $id;
            
            // Load Records
            $sql = "
                SELECT *, 
                    ST_GeometryType(the_geom) as geomtype,
                    ST_AsText(the_geom) as wkt
                FROM public.{$table} 
                WHERE $where 
                LIMIT 1 OFFSET 0";
            $record = $this->database_model->getAll($sql, $values);
            $record = reset($record);
            $result[$table][] = $record;
        }
        
        // Select application database
        $this->database_model->selectDatabase();

        return $result;
    }
    
    public function findRecordsByTerms($table, $terms) {
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Prepare terms
        $terms = explode(' ', $terms);
        foreach ($terms as &$term) $term = "'$term'";
        
        // prepare fields
        $where = array();
        foreach ($table->attributes as $k => $v) {
            if (strstr($v, 'character') || strstr($v, 'text')) {
                foreach ($terms as $term) {
                    $fields[] = $k;
                }
            }
        }
        
        // Prepare vector
        $vector = "to_tsvector(".implode(" || ' ' || ", $fields).")";
        // Prepare query
        $query = "to_tsquery(".implode(" & ", $terms).")";

        // Load records
        $sql = "
            SELECT *, 
                ST_GeometryType(the_geom) as geomtype,
                ST_AsText(the_geom) as wkt,
                ts_rank_cd($vector, $query, 32) AS rank
            FROM public.{$table->name} 
            WHERE $vector @@ $query
            ORDER BY rank DESC
            LIMIT 10 OFFSET 0";
        $result = $this->database_model->getAll($sql);

        // Select application database
        $this->database_model->selectDatabase();

        return $result;
    }
    
    public function loadOwnerRecords($owner, $table, $where = ' true ', $values = array(), $limit = 20) {
        
        $result = $this->loadRecords($table, $where, $values, $limit);
        
        if (!empty($result)) {
            if (isset($result[0]['owner'])) {
                $list = array();
                foreach ($result as $record) {
                    if ($record['owner'] == $owner) $list[] = $record;
                }
                $result = $list;
            }
        }
        return $result;
    }
    
    public function saveRecord($table, $record, $owner, $geom = false, $proj = 'EPSG:4326', $format = 'wkt')
    {
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Check if record exists
        if (empty($record['gid'])) {

            // Prepare values and fields
            foreach ($table->attributes as $field => $type) {
                if (in_array($field, $this->exclude_field)) continue;
                $attr_str[] = $field;
                $value_str[] = '?';
                $values[] = $record[$field];
            }
            
            if (in_array('last_update', array_keys($record))) {
                $attr_str[] = 'last_update';
                $value_str[] = '?';
                $values[] = date('Y-m-d H:i:s');
            }
            if (in_array('owner', array_keys($record))) {
                $attr_str[] = 'owner';
                $value_str[] = '?';
                $values[] = $owner;
            }
                
            $attr_str = implode (',', $attr_str);
            $value_str = implode (',', $value_str);
            
            // Insert Record
            $sql = "INSERT INTO public.{$table->name} ($attr_str) VALUES ($value_str) RETURNING gid";
            $result = $this->database_model->getRow($sql, $values);
            if (!empty($result)) $record['gid'] = $result['gid'];
            
        }
        else {
            // Prepare values and fields
            foreach ($table->attributes as $field => $type) {
                if (in_array($field, $this->exclude_field)) continue;
                $attr_str[] = "$field = ?";
                $values[] = $record[$field];
            }
            
            
            if (in_array('last_update', array_keys($record))) {
                $attr_str[] = 'last_update = ?';
                $values[] = date('Y-m-d H:i:s');
            }
            /*
            if (in_array('owner', array_keys($record))) {
                $attr_str[] = 'owner = ?';
                $values[] = $owner;
            }
            */
            $attr_str = implode (',', $attr_str);
            $values[] = $record['gid'];
            
            // Update Record
            $sql = "UPDATE public.{$table->name} SET $attr_str WHERE gid = ?";
            $this->database_model->exec($sql, $values);
        }
        
        // Select application database
        $this->database_model->selectDatabase();
        return $record;
    }
    
    public function saveGeometry($table, $record, $geom, $proj = 4326, $format = 'wkt')
    {
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Check if table exists
        $sql = "SELECT * FROM public.{$table->name} WHERE gid = {$record['gid']}";
        $exists = $this->database_model->getRow($sql);
        
        // Prepare values and fields
        $values[] = $record['gid'];
        
        // Update Record
        // ISSUE trying to use PDO. Exception returned.
        //$sql = "UPDATE public.{$table->name} SET $attr_str WHERE gid = ?";
        $sql = "
            UPDATE public.{$table->name} 
            SET the_geom = ST_GeomFromText('$geom', $proj) 
            WHERE gid = ?";
        $this->database_model->exec($sql, $values);

        // Select application database
        $this->database_model->selectDatabase();
        return $record;
    }
    
    public function deleteRecords($table, $selected) {
        
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // prepare delete filter
        foreach ($selected as $id) {
            $where_str[] = $id;
        }
        $where_str = implode($where_str);
        
        $sql = "DELETE FROM {$table->name} WHERE gid IN ($where_str)";
        $result = $this->database_model->exec($sql);

        // Select application database
        $this->database_model->selectDatabase();
    }
    
    public function optsRecordsPerPage() {
        return array(10, 50, 100, 500);
    }
    
    public function getExcludeFields() {
        return $this->exclude_field;
    }
    
    public function loadAllSRID() {
        
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Create table
        $sql = "SELECT * FROM public.spatial_ref_sys";
        $result = $this->database_model->getAll($sql);
        
        // Select application database
        $this->database_model->selectDatabase();
        
        return $result;
    }
    
    public function loadAllGeomTypes() {
        return array('POINT', 'LINESTRING', 'POLYGON', 'MULTYPOINT', 'MULTILINESTRING', 'MULTIPOLYGON');
    }
    
    public function addColumn($table, $name, $type) {
        
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Create table
        $sql = "ALTER TABLE public.{$table->name} ADD COLUMN $name $type";
        $result = $this->database_model->getAll($sql);
        
        // Select application database
        $this->database_model->selectDatabase();
        
        return $result;
    }
    
    public function renameColumn($table, $column, $newname) {
        
        // Select user database
        $this->database_model->selectDatabase('userdata');
        
        // Create table
        $sql = "ALTER TABLE {$table->name} RENAME COLUMN $column TO $newname";
        $result = $this->database_model->getAll($sql);
        
        // Select application database
        $this->database_model->selectDatabase();
        
        return $result;
    }
    
    public function deleteFields($table, $selected) {
        
        // Select user database
        $this->database_model->selectDatabase('userdata');

        foreach ($selected as $fieldname) {
            $sql = "ALTER TABLE public.{$table->name} DROP COLUMN $fieldname CASCADE";
            $result = $this->database_model->exec($sql);
        }

        // Select application database
        $this->database_model->selectDatabase();
    }
    
    public function getExternalTable($mslayer) {
        try {
            $tparams = explode(' ', $mslayer->connection);
            foreach ($tparams as &$tparam) {
                $kv = explode('=', $tparam);
                $params[$kv[0]] = $kv[1];
            }
            @preg_match("/from (?P<name>\w+)/i", $mslayer->data, $matches);
            if (empty($matches)) return false;
            $tablename = $matches[1];
            try {
                $result = R::addDatabase('temp',"pgsql:host={$params['host']};dbname={$params['dbname']}",$params['user'],$params['password']);
                R::selectDatabase('temp');
                $sql = "
                    SELECT attname FROM pg_attribute, pg_type
                    WHERE typname = '$tablename'
                    AND attrelid = typrelid
                    AND attname NOT IN ('cmin', 'cmax', 'ctid', 'oid', 'tableoid', 'xmin', 'xmax')
                ";
                $result = R::getAll($sql);
                if (!empty($result)) {
                    foreach ($result as $item) {
                        $table['fields'][] = $item['attname'];
                    }
                }
                $sql = "SELECT Find_SRID('public', '$tablename', 'the_geom')";
                $result = R::getRow($sql);
                if (!empty($result)) $table['srs'] = 'EPSG:'.$result['find_srid'];
                else {
                    $table['error'] = 'Could not get SRID from the_geom field';
                }
                $sql = "SELECT ST_extent(the_geom) from $tablename";
                $result = R::getRow($sql);
                if (!empty($result)) {
                    if (empty($result['st_extent'])) $table['error'] = 'Could not get table EXTENT. Is the table empty?';
                    else {
                        $pattern = "/BOX\((.+) (.+),(.+) (.+)\)/i";
                        @preg_match_all($pattern, $result['st_extent'], $matches);
                        if (!empty($matches)) {
                            $table['extent'] = round($matches[1][0], 3).' '.round($matches[2][0], 3).' '.round($matches[3][0], 3).' '.round($matches[4][0], 3);
                        }
                        else $table['error'] = 'Could not get table EXTENT';
                    }
                }
                else $table['error'] = 'Could not get table EXTENT';
            }
            catch (PDOException $e) {
                $table['error'] = $e->getMessage();
            }
        }
        catch (Exception $e) {
            $table['error'] = $e->getMessage();
        }
        
        R::selectDatabase('default');
        return $table;
    }
    
}

?>
