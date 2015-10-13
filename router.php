<?php 
/**
 * @author Lloyd Zhou (lloydzhou@qq.com)
 * A barebones router for PHP. It matches urls and executes PHP functions.
 * automatic get variable based on handler function parameter list.
 */
class Router {
    protected $_tree = array();
    protected $_error = array();
    protected $_hook = array();
    const COLON = ':';
    const SEPARATOR = '/';
    const LEAF = 'LEAF';
    public function __construct($tree=array(), $error=array(), $hook=array()){
        $this->_tree = $tree;
        $this->_error = $error;
        $this->_hook = $hook;
    }
    /* helper function to create the tree based on urls, handlers will stored to leaf. */
    protected function match_one_path(&$node, $tokens, $cb, $hook){
        $token = array_shift($tokens);
        if (!array_key_exists(self::COLON, $node))
            $node[self::COLON] = array();
        $is_token = ($token && self::COLON == $token[0]);
        $real_token = $is_token ? substr($token, 1) : $token;
        if ($is_token) $node = &$node[self::COLON];
        if ($real_token && !array_key_exists($real_token, $node))
            $node[$real_token] = array();
        if ($real_token)
            return $this->match_one_path($node[$real_token], $tokens, $cb, $hook);
        $node[self::LEAF] = array($cb, (array)($hook));
    }
    /* helper function to find handler by $path. */
    protected function _resolve($node, $tokens, $params){
        $current_token = array_shift($tokens);
        if (!$current_token && array_key_exists(self::LEAF, $node))
            return array($node[self::LEAF][0], $node[self::LEAF][1], $params);
        if (array_key_exists($current_token, $node))
            return $this->_resolve($node[$current_token], $tokens, $params);
        foreach($node[self::COLON] as $child_token=>$child_node){
            /**
             * if $current_token not null, and $child_token start with ":"
             * set the parameter named $pname and resolve next $path.
             * if can not resolve with next $path, restore the parameter named $pname.
             */
            $pvalue = array_key_exists($child_token, $params) ? $params[$child_node] : null;
            $params[$child_token] = $current_token;
            if (!$current_token && array_key_exists(self::LEAF, $child_node))
                return array($child_node[self::LEAF][0], $child_node[self::LEAF][1], $params);
            list($cb, $hook, $params) = $this->_resolve($child_node, $tokens, $params);
            if ($cb) return array($cb, $hook, $params);
            $params[$child_token] = $pvalue;
        }
        return array(false, '', null);
    }
    public function resolve($method, $path, $params){
        if (!array_key_exists($method, $this->_tree)) return array(null, "Unknown method: $method", null);
        $tokens = explode(self::SEPARATOR, str_replace('.', self::SEPARATOR, $path));
        return $this->_resolve($this->_tree[$method], $tokens, $params);
    }
    /* API to find handler and execute it by parameters. */
    public function execute($params=array(), $method=null, $path=null){
        $method = $method ? $method : $_SERVER['REQUEST_METHOD'];
        $path = trim($path ? $path : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), self::SEPARATOR);
        list($cb, $hook, $params) = $this->resolve($method, $path, $params);
        if (!is_callable($cb)) return $this->error(405, "Could not resolve [$method] $path");
        /**
         * merge the $roter and all $request values into $params.
         * auto call the "before" hook before execute the callback handler, and call "after" hook with return value of handler.
         * need define the hook with @param $params, and @return $params, so can change it in the hook handler.
         * if the hook return false, will trigger 406 error handler.
         */
        $input = ('application/json' == $_SERVER['HTTP_CONTENT_TYPE'] || 'application/json' == $_SERVER['CONTENT_TYPE'])
            ? (array)json_decode(file_get_contents('php://input'), true) : array();
        $params = array_merge($params, $_SERVER, $_REQUEST, $input, $_FILES, $_COOKIE, isset($_SESSION)?$_SESSION:array(), array('router'=>$this));
        foreach(array_merge(array('before'), $hook) as $i=>$h){
            if (!($params = $this->hook($h, $params))) return $this->error(406, "Failed to execute hook: $h");
        }
        /**
         * auto get the variable list based on the callback handler parameter list.
         * if the named parameter set in user defined $params or in request, get the value.
         * if the named parameter not set, get the default value in callback handler.
         */
        $ref = is_array($cb) && isset($cb[1]) ? new ReflectionMethod($cb[0], $cb[1]) : new ReflectionFunction($cb);
        $args = $ref->getParameters();
        array_walk($args, function(&$p, $i, $params){
            $p = isset($params[$p->getName()]) ? $params[$p->getName()] : ($p->isOptional() ? $p->getDefaultValue() : null);
        }, $params);
        /* execute the callback handler and pass the result into "after" hook handler.*/
        return $this->hook('after', call_user_func_array($cb, $args), $this);
    }
    public function match($method, $path, $cb, $hook=array()){
        if (!is_array($method)) $method = array($method=>array($path=>$cb));
        $tokens = explode(self::SEPARATOR, str_replace('.', self::SEPARATOR, trim($path, self::SEPARATOR)));
        foreach($method as $m=>$routes){
            if (!array_key_exists($m, $this->_tree)) $this->_tree[$m] = array();
            $this->match_one_path($this->_tree[$m], $tokens, $cb, $hook);
        }
        return $this;
    }
    /* register api based on request method. also register "error" and "hook" API. */
    public function __call($name, $args){
        if (in_array($name, array('get', 'post', 'put', 'patch', 'delete', 'trace', 'connect', 'options', 'head'))){
            array_unshift($args, strtoupper($name));
            return call_user_func_array(array($this, 'match'), $args);
        }
        if (in_array($name, array('error', 'hook'))){
            $key = array_shift($args);
            if (($_name = '_'. $name) && isset($args[0]) && is_callable($args[0]))
                $this->{$_name}[$key] = $args[0];
            else if (isset($this->{$_name}[$key]) && is_callable($this->{$_name}[$key]))
                return call_user_func_array($this->{$_name}[$key], $args);
            else return $args[0];
            return $this;
        }
    }
}

