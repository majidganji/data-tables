<?php

namespace laravel\dataTables;

use function array_column;
use function array_filter;
use function array_push;
use function array_search;
use function count;
use function dd;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDO;
use function request;

/**
 * Class DataTable
 * @property  Builder $_eloquent
 * @property  Request $_request
 */
class DataTable {

    private $_request;
    private $_columns;
    private $_eloquent;
    private $_query;

    public function __construct(Request $request) {
        $this->_request = $request->all();
    }

    public static function newInstance() {
        return new self(request());
    }

    public function setColumns($columns) {
        $this->_columns = $columns;
        return $this;
    }

    /**
     * @param Builder $eloquent
     * @return $this
     */
    public function setEloquent(Builder $eloquent) {
        $this->_eloquent = $eloquent;
        return $this;
    }

    public function setQuery($query) {
        $this->_query = $query;
        return $this;
    }

    public function eloquentResult() {
        // Total data set length
        $recordsTotal = $this->_eloquent->count();

        $this->filterEloquent();

        // Data set length after filtering
        $recordsFiltered = $this->_eloquent->count();

        if ($this->_request['length'] != -1) {
            $this->_eloquent = $this->_eloquent->skip(intval($this->_request['start']))->take(intval($this->_request['length']));
        }

        // Build the SQL query string from the request
        $this->orderEloquent();

        // Main query to actually get the data
        $data = $this->_eloquent->get();

        return [
            "draw" => intval($this->_request['draw']),
            "recordsTotal" => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data" => $this->_dataOutput($data)
        ];
    }

    /**
     * Perform the SQL queries needed for an server-side processing requested,
     * utilising the helper functions of this class, limit(), order() and
     * filter() among others. The returned array is ready to be encoded as JSON
     * in response to an SSP request, or can be modified if needed before
     * sending back to the client.
     *
     * @param  array $request Data sent to server by DataTables
     * @param  array $columns Column information array
     * @param  string $query SQL query
     * @return array  Server-side processing response array
     */
    public function queryResult() {
        $bindings = [];

        // Build the SQL query string from the request
        $limit = $this->_limit();


        $order = $this->_order();

        $where = $this->_filter($bindings);

        // Main query to actually get the data
        $data = $this->_sql_exec("SELECT SQL_CALC_FOUND_ROWS
                '' AS indexColumn, `" . implode("`, `", $this->_pluck($this->_columns, 'db')) . "`
			 FROM
			    ($this->_query) tmpTable
			 $where
			 $order
             $limit", $bindings);

        // Data set length after filtering
        $resFilterLength = $this->_sql_exec("SELECT FOUND_ROWS() AS FOUND_ROWS");
        $recordsFiltered = $resFilterLength[0]['FOUND_ROWS'];

        // Total data set length
        $resTotalLength = $this->_sql_exec("SELECT COUNT(*) AS recordsTotal
			 FROM ({$this->_query})tmpTable");
        $recordsTotal = $resTotalLength[0]['recordsTotal'];

        return [
            "draw" => intval($this->_request['draw']),
            "recordsTotal" => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data" => $this->_dataOutput($data)
        ];
    }

    /**
     * Create the data output array for the DataTables rows
     *
     * @param  array $data Data from the SQL get
     * @return array Formatted data in a row based format
     */
    private function _dataOutput($data) {
        $out = [];


        for ($i = 0, $ien = count($data); $i < $ien; $i++) {
            $row = [];

            $row['indexColumn'] = intval($this->_request['start']) + 1 + $i;

            for ($j = 0, $jen = count($this->_columns); $j < $jen; $j++) {
                $column = $this->_columns[$j];

                // Is there a formatter?
                if (isset($column['relation']) && isset($column['formatter'])) {
                    $row[$column['dt']] = $column['formatter']($data[$i][$column['relation']][$column['db']], $data[$i][$column['relation']]);
                } else if (isset($column['relation']) && !isset($column['formatter'])) {
                    $row[$column['dt']] = $data[$i][$column['relation']][$column['db']] ?? '';
                } else if (isset($column['formatter'])) {
                    $row[$column['dt']] = $column['formatter']($data[$i][$column['db']], $data[$i]);
                } else {
                    $row[$column['dt']] = $data[$i][$this->_columns[$j]['db']];
                }
            }

            $out[] = $row;
        }
        return $out;
    }

    /**
     * Paging
     * Construct the LIMIT clause for server-side processing SQL query
     *
     * @param  array $request Data sent to server by DataTables
     * @param  array $columns Column information array
     * @return string SQL limit clause
     */
    private function _limit() {
        $limit = '';

        if (isset($this->_request['start']) && $this->_request['length'] != -1) {
            $limit = "LIMIT " . intval($this->_request['start']) . ", " . intval($this->_request['length']);
        }

        return $limit;
    }

    /**
     * Ordering
     * Construct the ORDER BY clause for server-side processing SQL query
     *
     * @param  array $request Data sent to server by DataTables
     * @param  array $columns Column information array
     * @return string SQL order by clause
     */
    private function _order() {
        $order = '';

        if (isset($this->_request['order']) && count($this->_request['order'])) {
            $orderBy = [];
            $dtColumns = $this->_pluck($this->_columns, 'dt');

            for ($i = 0, $ien = count($this->_request['order']); $i < $ien; $i++) {
                // Convert the column index into the column data property
                $columnIdx = intval($this->_request['order'][$i]['column']);
                $requestColumn = $this->_request['columns'][$columnIdx];

                $columnIdx = array_search($requestColumn['data'], $dtColumns);
                $column = $this->_columns[$columnIdx];

                if ($requestColumn['orderable'] == 'true') {
                    $dir = $this->_request['order'][$i]['dir'] === 'asc' ? 'ASC' : 'DESC';

                    $orderBy[] = '`' . $column['db'] . '` ' . $dir;
                }
            }

            $order = 'ORDER BY ' . implode(', ', $orderBy);
        }

        return $order;
    }

    /**
     * Searching / Filtering
     * Construct the WHERE clause for server-side processing SQL query.
     * NOTE this does not match the built-in DataTables filtering which does it
     * word by word on any field. It's possible to do here performance on large
     * databases would be very poor
     *
     * @param  array $bindings Array of values for PDO bindings, used in the
     *                         sql_exec() function
     * @return string SQL where clause
     */
    private function _filter(&$bindings) {
        $globalSearch = [];
        $columnSearch = [];
        $dtColumns = $this->_pluck($this->_columns, 'dt');
        if (isset($this->_request['search']) && $this->_request['search']['value'] != '') {
            $str = $this->_request['search']['value'];

            for ($i = 0, $ien = count($this->_request['columns']); $i < $ien; $i++) {
                $requestColumn = $this->_request['columns'][$i];
                $columnIdx = array_search($requestColumn['data'], $dtColumns);
                $column = $this->_columns[$columnIdx];

                if ($requestColumn['searchable'] == 'true') {
                    $binding = $this->_bind($bindings, '%' . $str . '%', PDO::PARAM_STR);
                    $globalSearch[] = "`" . $column['db'] . "` LIKE " . $binding;
                }
            }
        }

        // Individual column filtering
        for ($i = 0, $ien = count($this->_request['columns']); $i < $ien; $i++) {
            $requestColumn = $this->_request['columns'][$i];

            $columnIdx = array_search($requestColumn['data'], $dtColumns);

            $column = $this->_columns[$columnIdx];

            $str = $requestColumn['search']['value'];

            if ($requestColumn['searchable'] == 'true' && $str != '') {
                $binding = $this->_bind($bindings, '%' . $str . '%', PDO::PARAM_STR);
                $columnSearch[] = "`" . $column['db'] . "` LIKE " . $binding;
            }

        }

        // Combine the filters into a single string
        $where = '';

        if (count($globalSearch)) {
            $where = '(' . implode(' OR ', $globalSearch) . ')';
        }

        if (count($columnSearch)) {
            $where = $where === '' ? implode(' AND ', $columnSearch) : $where . ' AND ' . implode(' AND ', $columnSearch);
        }

        if ($where !== '') {
            $where = 'WHERE ' . $where;
        }

        return $where;
    }

    /**
     * Execute an SQL query on the database
     *
     * @param  resource $db Database handler
     * @param  array $bindings Array of PDO binding values from bind() to be
     *                         used for safely escaping strings. Note that this can be given as the
     *                         SQL query string if no bindings are required.
     * @param  string $sql SQL query to execute.
     * @return array         Result from the query (all rows)
     */
    private function _sql_exec($sql, $bindings = []) {
        // Execute
        try {
            $rows = DB::select($sql, $bindings);

            $rows = json_decode(json_encode($rows), true);
        } catch (PDOException $e) {
            $this->_fatal("An SQL error occurred: " . $e->getMessage());
        }

        // Return all
        return $rows;
    }

    /**
     * Throw a fatal error.
     * This writes out an error message in a JSON string which DataTables will
     * see and show to the user in the browser.
     *
     * @param  string $msg Message to send to the client
     */
    private function _fatal($msg) {
        echo json_encode([
            "error" => $msg
        ]);

        exit(0);
    }

    /**
     * Create a PDO binding key which can be used for escaping variables safely
     * when executing a query with sql_exec()
     *
     * @param  array &$a Array of bindings
     * @param  *      $val  Value to bind
     * @param  int $type PDO field type
     * @return string       Bound key to be used in the SQL where this parameter
     *                   would be used.
     */
    private function _bind(&$a, $val, $type) {
        $key = ':binding_' . count($a);


        $a[$key] = $val;

        return $key;
    }

    /**
     * Pull a particular property from each assoc. array in a numeric array,
     * returning and array of the property values from each item.
     *
     * @param  array $columns Array to get data from
     * @param  string $prop Property to read
     * @return array        Array of property values
     */
    private function _pluck($columns, $prop) {
        return array_filter(array_column($columns, $prop));
    }

    /**
     * Ordering Eloquent
     * Construct the ORDER BY clause for server-side processing SQL query
     *
     * @return void order by clause
     */
    private function orderEloquent() {
        $orders = [];

        if (isset($this->_request['order']) && count($this->_request['order'])) {
            $dtColumns = $this->_pluck($this->_columns, 'dt');
            for ($i = 0, $ien = count($this->_request['order']); $i < $ien; $i++) {
                // Convert the column index into the column data property
                $columnIdx = intval($this->_request['order'][$i]['column']);
                $requestColumn = $this->_request['columns'][$columnIdx];
                $columnIdx = array_search($requestColumn['data'], $dtColumns);
                $column = $this->_columns[$columnIdx];

                if ($requestColumn['orderable'] == 'true') {
                    $dir = $this->_request['order'][$i]['dir'] === 'asc' ? 'ASC' : 'DESC';

                    if (! isset($column['relation'])) {
                        $orders[] = ['column' => $column['db'], 'dir' => $dir];
                    }
                }
            }
        }

        foreach ($orders as $order) {
            $this->_eloquent->orderBy($order['column'], $order['dir']);
        }
    }

    /**
     * Searching / Filtering Eloquent
     * Construct the WHERE clause for server-side processing SQL query.
     * NOTE this does not match the built-in DataTables filtering which does it
     * word by word on any field. It's possible to do here performance on large
     * databases would be very poor
     *
     * @return void
     */
    private function filterEloquent() {
        $globalSearch = [];
        $columnSearch = [];
        $dtColumns = $this->_pluck($this->_columns, 'dt');

        if (isset($this->_request['search']) && $this->_request['search']['value'] != '') {
            $str = $this->_request['search']['value'];

            for ($i = 0, $ien = count($this->_request['columns']); $i < $ien; $i++) {
                $requestColumn = $this->_request['columns'][$i];
                $columnIdx = array_search($requestColumn['data'], $dtColumns);
                $column = $this->_columns[$columnIdx];

                if ($requestColumn['searchable'] == 'true') {
                    $array = [];

                    if (isset($column['relation'])) {
                        $array['relation'] = $column['relation'];
                    }

                    if (isset($column['filter'])) {
                        $array['filter'] = $column['filter']($column['db'], $str);
                    }else{
                        $array['filter'] = $array['filter'] = [
                            'column' => $column['db'],
                            'where' => 'LIKE',
                            'value' => '%' . $str . '%',
                        ];
                    }

                    array_push($globalSearch, $array);
                }
            }
        }

        // Individual column filtering
        for ($i = 0, $ien = count($this->_request['columns']); $i < $ien; $i++) {
            $requestColumn = $this->_request['columns'][$i];
            $columnIdx = array_search($requestColumn['data'], $dtColumns);

            $column = $this->_columns[$columnIdx];

            $str = $requestColumn['search']['value'];

            if ($requestColumn['searchable'] == 'true' && $str != '') {
                $array = [];

                if (isset($column['relation'])) {
                    $array['relation'] = $column['relation'];
                }

                if (isset($column['filter'])) {
                    $array['filter'] = $column['filer']($column['db'], $str);
                }else{
                    $array['filter'] = [
                        'column' => $column['db'],
                        'where' => 'LIKE',
                        'value' => '%' . $str . '%',
                    ];
                }

                array_push($globalSearch, $array);
            }
        }

        $wheres = array_merge($globalSearch, $columnSearch);


        if (count($wheres) < 1) {
            return;
        }

        $this->_eloquent = $this->_eloquent->where(function ($query) use ($wheres) {
            foreach ($wheres as $where) {
                if (isset($where['relation'])){
                    $query->orWhereHas($where['relation'], function($subQuery) use ($where) {
                        $subQuery->where($where['filter']['column'], $where['filter']['where'], $where['filter']['value']);
                    });
                }else{
                    $query->orwhere($where['filter']['column'], $where['filter']['where'], $where['filter']['value']);
                }
            }
        });
    }
}