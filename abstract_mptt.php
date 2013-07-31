<?php
/**
 * Provides generic methods to work with a modified pre-order tree traversal (mptt) data structure.
 *
 * Currently this class requires an instance of a Zend_Db object (as it was developed for a system using the Zend Framework ver 1.x).
 * This dependency is injected into the object via the constructor. If you have a different DB abstraction implementation, you may
 * be able to easily modify this class to accept it by changing the constructor parameter type, if your replacement object supports
 * the following methods (or consider a facade to it): beginTransation(), commit(), exec(), rollBack() and fetchAll().
 *
 * Important: You should consider if your concrete class needs to implement some kind of locking mechanism to prevent simultaneous
 * update operations to the tree. If simultaneous updates to the tree are allowed, corruption of the structure is highly likely, especially
 * if the table is a single MPTT instead of multiple trees sharing the same DB table (@see $tree_grp_col).
 *
 * @author martynbutty@googlemail.com
 * 
 * @link http://ftp.nchu.edu.tw/MySQL/tech-resources/articles/hierarchical-data.html
 * 
 */
class abstract_mptt{

    /**
     * @var string The name of the DB table used to store the tree data
     */
    private $table_name;

    /**
     * @var string The name of the DB col representing the 'left' value of a node
     */
    private $left_col = 'lft';

    /**
     * @var string The name of the DB col representing the 'right' value of a node
     */
	private $right_col = 'rgt';

    /**
     * @var string The name of the PUK in the DB table
     */
    private $_id = 'id';

    /**
     * @var string The name of the DB column used to store the name of a node
     */
    private $_name = 'name';

    /**
     * @var string Default name value for the root node of a tree
     */
    private $root = 'root';

    /**
     * @var bool When performing various operations, if the root node for the tree does not exist, should it be auto created
     */
    private $auto_create_root = true;

	/**
	 * If set, should be the name of the DB table column which identifies a single tree within
     * the table. This is in effect making the table a 'forest' of trees, enabling
	 * separation and faster updates to otherwise large trees. If not set, all logically
     * distinct trees will all belonging to the same root node. This would typically be set
     * in the constructor of a concrete class of this abstract class
	 * @var String
	 */
	private $tree_grp_col = null;

	/**
	 * Used in combination with $tree_grp_col. If tree_grp_col is set, this var holds the value of
	 * that column we are grouping the current tree by. If one or the other is not set, we cannot 
	 * separate the individual trees and will have to revert to a common root parent element.
     * This would typically be set in the constructor of a concrete class of this abstract class
	 * @var String
	 */
	private $tree_grp_col_val = null;

    /**
     * @var Zend_Db instantiated object to allow the class to update the DB
     */
    private $_db;
	
	public function __construct(Zend_Db $db){
        $this->_db = $db;
    }

    public function set_table_name($name){
        $this->table_name = $name;
    }

    public function get_table_name(){
        return $this->table_name;
    }
	
	public function set_left_col($name){
		$this->left_col = $name;
	}
	
	public function set_right_col($name){
		$this->right_col = $name;
	}

	public function get_right_col(){
		return $this->right_col;
	}

	public function get_left_col(){
		return $this->left_col;
	}
	
	public function set_id($id){
		$this->_id = $id;
	}
	
	public function set_name_col($name){
		$this->_name = $name;
	}

	public function set_root_name($name){
		$this->root = $name;
	}
	
	public function get_root_name(){
		return $this->root;
	}
	
	/**
	 * Set whether to auto create the root node if it does not exist.
	 * @param Boolean $val
	 */
	public function set_auto_root_node_creation($val){
		if(is_bool($val)) $this->auto_create_root = $val;
	}
	
	public function set_tree_grp_col($val){
		$this->tree_grp_col = $val;
	}

	public function get_tree_grp_col(){
		if(empty($this->tree_grp_col_val) && !empty($this->tree_grp_col)){
			trigger_error("** Warning, tree group col is set but tree grp col val is empty. Falling back to single tree mode");
			return null;
		}
		return $this->tree_grp_col;
	}

	public function set_tree_grp_col_val($val){
		$this->tree_grp_col_val = $val;
	}

	public function get_tree_grp_col_val(){
		if(!empty($this->tree_grp_col_val) && empty($this->tree_grp_col)){
            trigger_error("** Warning, tree group col val is set but tree grp col is empty. Falling back to single tree mode");
			return null;
		}
		return $this->tree_grp_col_val;
	}

	/**
	 * Checks if tree_grp_col and tree_grp_col_val are set. If they are both set then generate and return a where clause
	 * chunk to add to the SQL, otherwise returns empty. Future enhancement could use reflection to check for the existence
	 * of the table col specified in tree_grp_col.
	 * @return String
	 */
	protected function get_tree_grp_where(){
		$sql = '';
		$group_col = $this->get_tree_grp_col();
		if(!empty($group_col)){
			$group_col_val = $this->get_tree_grp_col_val();
			if(!empty($group_col_val)){
				$sql.=" AND `{$group_col}` = '{$group_col_val}'";
			}
		}
		return $sql;
	}

	/**
	 * 
	 * Add a node to the tree. If specified, node will be added as a child of $parent, otherwise as a child of the root element.
	 * 
	 * $parent can be the name or id of the node, in which case an instance will be looked up and created, or you can pass in an
	 * instance of plg_bc_online_form_field itself (You may have created one already for validation checks).
	 * 
	 * $position tells us where in the nodes siblings we want to insert this new node (base zero), 0 = left most position.
	 * 
	 * @param string|int|object $parent
	 * @param int $position
     *
     * @throws Exception
	 */
	public function add_node($parent = null, $position = 999999){
		if(is_null($parent)) $parent = $this->root;				
		
		if(is_int($parent)){
			$parent_node = $this->get_node_by_id($parent);
		}elseif($parent instanceof self){ 
			$parent_node = array(0 => $parent->get_column_values()); 
		}
		else{
			$parent_node = $this->get_node_by_name($parent);
		}
		
		if(count($parent_node) > 1){
            trigger_error("abstract_mptt::add_node() more than one parent node matches '$parent'. Using first one found, but this probably shouldn't have happened.");
			$parent_node = array(0 => $parent_node[0]);
		}
		
		if(count($parent_node) == 1){
			if(self::calc_number_children($parent_node[0][$this->left_col], $parent_node[0][$this->right_col]) == 0){
				// Parent node doesn't have any children, so add new node as its only child
				$insert_coords = array($this->left_col => $parent_node[0][$this->right_col], 
										$this->right_col => $parent_node[0][$this->right_col] + 1);
				$this->insert_node($insert_coords);
			}else{
                // Parent has children. Work out position in the tree relative to its siblings
				$i_children = $this->get_immediate_children($parent_node[0]);
				if(count($i_children) < 1){
                    // We have a problem, possibly just the tree structure is corrupt (lft and rgt vals of parent).
					// @todo Re-build the tree?
				}else{
					if($position == 0){
                        // adding this node as first child of parent
						$left_insert = $parent_node[0][$this->left_col] + 1;
					}else{
						if($position > count($i_children)) $position = count($i_children);
						// Invalid position given, add node as last sibling
                        // $i_children[0] is the parent node so don't need a -1 on the index below

						if(isset($i_children[$position])){
							$sibling = $this->get_node_by_name($i_children[$position]['name']);
						}else{
                            // Adding node as last sibling of parent, so get last sibling of the parent
							$sibling = $this->get_node_by_name($i_children[$position - 1]['name']);
						}
						$left_insert = $sibling[0][$this->right_col] + 1;
					}
					$right_insert = $left_insert + 1;
					$insert_coords = array($this->left_col => $left_insert, $this->right_col => $right_insert);
					$this->insert_node($insert_coords);
				}
			}
			// Node inserted into the tree. Update it's relation/structure data
			$l = $this->left_col;
			$r = $this->right_col;
			$this->$l = $insert_coords[$this->left_col];
			$this->$r = $insert_coords[$this->right_col];
		}else{
			throw new Exception('Could not locate parent node "'.$parent.'"');
		}	
		
	}

    /**
     * Move a node (and its children) to a new parent within the tree
     * @param null $new_parent
     * @throws Exception
     */
    public function move_node($new_parent = null){
		if(is_null($new_parent)) throw new Exception('Cannot move node - no parent node has been specified');
		if(is_int($new_parent)){
			$parent_node = $this->get_node_by_id($new_parent);
		}elseif($new_parent instanceof plg_bc_online_form_field){
			$parent_node = array(0 => $new_parent->get_column_values());
		}
		else{
			$parent_node = $this->get_node_by_name($new_parent);
		}
		
		if(empty($parent_node) || count($parent_node) > 1){
			throw new Exception("Could not find parent node matching '$new_parent'. ");
		}
		
		$current_parent_id = $this->get_parent_id();
		if($current_parent_id == $parent_node[0]['id']){
            trigger_error("Trying to move a node but it's current parent is the same as the new/target parent. Future versions should allow this to re-order fields, but currently this isn't implemented");
			return;
		}

		if($this->id == $parent_node[0]['id']){
            trigger_error("Trying to move node to be parent of itself");
			return;
		}
		
		$l = $this->left_col;
		$r = $this->right_col;
		$start = $parent_node[0][$this->left_col];
		$treesize = $this->$r - $this->$l + 1;
		// Make room in the tree to move the node into it's new position
		$this->shift_values($start, $treesize);
				
		if($this->$l >= $start){
			/* Going to move a node/subtree down the tree, which will leave a gap in the lft and rgt numbers.
			 * Take a note of where this gap starts from so we can fix the numbers afterwards.	*/
			$start_of_gap = $this->$r;
			
			/* previous call has just changed our moving node(s) lft and rgt values. Update our object instance to match 
			 * it's values in the DB so rest of calls are valid		 */
			$this->$l = $this->$l + $treesize;
			$this->$r = $this->$r + $treesize;
		}else{
			// moving node up the tree
			$start_of_gap = $this->$l;
		}
		
		// Move the node(s)
		$delta = $start - $this->$l + 1;
		$this->shift_range($this->$l, $this->$r, $delta);
		
		// Fix the gap in the tree left by the move
		$this->shift_values($start_of_gap+1, ($treesize * -1));
	}

    /**
     * Move the node up (left) amongst its siblings. If this node has children, they will be moved with it
     * @throws Exception
     */
    public function move_node_up(){
		$current_parent_id = $this->get_parent_id();

		$l = $this->left_col;
		$r = $this->right_col;
		$parent_node = $this->get_node_by_id($current_parent_id);
		if($parent_node[0][$this->left_col] == ($this->$l - 1)){
            trigger_error('This element is already the first sibling of its parent, cannot move any further up. Try dragging it to a new parent');
            return;
        }

        $prev_sibling_id = $this->get_prev_sibling_id();
        if(is_null($prev_sibling_id)) {
            trigger_error('Could not find previous sibling of this node.');
            return;   
        }

        $sibling_node = $this->get_node_by_id($prev_sibling_id);
        if(empty($sibling_node) || count($sibling_node) > 1){
			throw new Exception("Could not find sibling node matching '$prev_sibling_id'. ");
		}

		// First make a hole in the tree to allow sufficient room to shuffle the nodes around
        $start = $sibling_node[0][$this->left_col]; 
        $treesize = $this->$r - $this->$l + 1;
		$this->shift_values($start, $treesize, 'up');
				
		// Now shuffle the node (and any children) that we are moving
		$range_l = $this->$l + $treesize; // The new left val of node to move after the above shuffle
		$range_r = $this->$r + $treesize; // The new right val of the node to move after the above shuffle
		$delta = $start - $range_l; // How mmuch to shift the moving nodes value by
		$this->shift_range($range_l, $range_r, $delta);

		// Move complete, need to close the gap to fix the tree structure
        // This is the current value of what was the previous sibling right col, and after the above shuffles, is the start of the gap we made to allow the move
        $start_of_gap = $sibling_node[0][$this->right_col] + $treesize;
		$this->shift_values($start_of_gap+1, ($treesize * -1));
	}

    /**
     * Move the node down (right) amongst its siblings. If this node has children, they will be moved with it
     * @throws Exception
     */
    public function move_node_down(){
		$current_parent_id = $this->get_parent_id();

		$l = $this->left_col;
		$r = $this->right_col;
		$parent_node = $this->get_node_by_id($current_parent_id);
		if($parent_node[0][$this->right_col] == ($this->$r + 1)){
            trigger_error('This element is already the last sibling of its parent, cannot move any further down. Try dragging it to a new parent');
            return;
        }

        $next_sibling_id = $this->get_next_sibling_id();
        if(is_null($next_sibling_id)) {
            trigger_error('Could not find next sibling of this node.');
            return;   
        }

        $sibling_node = $this->get_node_by_id($next_sibling_id);
        if(empty($sibling_node) || count($sibling_node) > 1){
			throw new Exception("Could not find sibling node matching '$next_sibling_id'. ");
		}

		// First make a hole in the tree to allow sufficient room to shuffle the nodes around
        $start = $sibling_node[0][$this->right_col]; 
        $treesize = $this->$r - $this->$l + 1;
		$this->shift_values($start, $treesize, 'down');
				
		// Now shuffle the node (and any children) that we are moving
		$range_l = $this->$l;
		$range_r = $this->$r;
		$delta = ($sibling_node[0][$this->right_col] + 1) - $this->$l;
		$this->shift_range($range_l, $range_r, $delta);

		// Move complete, need to close the gap to fix the tree structure
		$start_of_gap = ($sibling_node[0][$this->left_col] - $treesize) - 1; // The lft val of node before the one we moved
		$this->shift_values($start_of_gap+1, ($treesize * -1), 'down');
	}
	
	/**
	 * Given the name value for the name column, see if a node with that name exists, and if it does, get it.
	 * This assumes that the name will be unique across all nodes, but is not enforced here. If > 1 node with same name, first one found is used.
	 * If no name specified, will attempt to find the root node. If root node not found it will be created if auto_create_root is true
	 *
	 * @param string $name
	 * @return Array <results from fetchAll>
	 */
	public function get_node_by_name($name = ''){
		if(empty($name)) $name = $this->root;

		$sql = "SELECT `".$this->_name."`, `".$this->left_col."`, `".$this->right_col."` FROM `".$this->table_name."` WHERE `".$this->_name."` = '$name'";
		$sql.=$this->get_tree_grp_where();

		$res = $this->_db->fetchAll($sql);
	
		if($name == $this->root && empty($res) && $this->auto_create_root){
			$this->create_root_node();
			$res = $this->get_tree_by_name($name);
		}
	
		return $res;
	}
	
	/**
	 * Given the id, see if a node with that id exists, and if it does, get it.
	 * If no id specified, will attempt to find the root node. If root node not found it will be created if auto_create_root is true
	 *
	 * @param string $parent_id
	 * @return Array <results from fetchAll>
	 */
	public function get_node_by_id($id = null){
		if(is_null($id)){
			$res = $this->get_node_by_name($this->root);
		}else{
			$sql = "SELECT `".$this->_id."`, `".$this->left_col."`, `".$this->right_col."` FROM `".$this->table_name."`";
			$sql.=" WHERE `".$this->_id."` = ".$id;
			$res = $this->_db->fetchAll($sql);
		}
	
		return $res;
	}
	
	/**
	 * Given the name value for the _name column, see if a node with that name exists, and if it does, get all (if any) of it's children.
	 * This assumes that the name will be unique across all nodes, but is not enforced here. If > 1 node with same name, first one found is used.
	 * If no name specified, will attempt to find the root node. If root node not found it will be created if auto_create_root is true
	 * 
	 * @param string $parent_name
	 * @return Array <results from fetchAll>
	 */
	public function get_tree_by_name($parent_name = ''){
		if(empty($parent_name)) $parent_name = $this->root;

		$sql = "SELECT `".$this->_name."`, `".$this->left_col."`, `".$this->right_col."` FROM `".$this->table_name."` WHERE `".$this->_name."` = '$parent_name'";
		$sql.=$this->get_tree_grp_where();
		$res = $this->_db->fetchAll($sql);
		
		if($parent_name == $this->root && empty($res) && $this->auto_create_root){
			$this->create_root_node();
			$res = $this->get_tree_by_name($parent_name);
		}
		
		if(!empty($res)){
			$res = $this->get_tree($res[0][$this->left_col], $res[0][$this->right_col]);
		}
		
		return $res;
	}
	
	/**
	 * Given the id, see if a node with that id exists, and if it does, get all (if any) of it's children.
	 * If no id specified, will attempt to find the root node. If root node not found it will be created if auto_create_root is true
	 *
	 * @param string $parent_id
	 * @return Array <results from fetchAll>
	 */
	public function get_tree_by_id($parent_id = null){
		if(is_null($parent_id)){
			$res = $this->get_full_tree();
		}else{
			$sql = "SELECT `".$this->_id."`, `".$this->left_col."`, `".$this->right_col."` FROM `".$this->table_name."`";
			$sql.=" WHERE `".$this->_id."` = ".$parent_id;
			$res = $this->_db->fetchAll($sql);
		}
		
		if(is_null($parent_id) && empty($res) && $this->auto_create_root){
			$this->create_root_node();
			$res = $this->get_tree_by_id($parent_id);
		}
		
		if(!empty($res)){
			$res = $this->get_tree($res[0][$this->left_col], $res[0][$this->right_col]);
		}		
		
		return $res;
	}

    /**
     * Delete the current node. Any child nodes are kept @see delete_node_keep_children()
     * @param $node_id
     * @return bool
     */
    public function delete_node($node_id){
		$node = $this->get_node_by_id($node_id);
		if(empty($node[0][$this->_id]) || $node[0][$this->_id] != $node_id){
            trigger_error("abstract_mptt::delete_node - could not find node '$node_id'");
			return false;
		}
		
		if(self::calc_number_children($node[0][$this->left_col], $node[0][$this->right_col]) == 0){
			$this->delete_node_no_children($node);
		}else{
			$this->delete_node_keep_children($node);
		}
		
	}
	
	/**
	 * Deletes a node from the tree and re-orders the tree. Any children are kept and promoted up 
	 * the tree accordingly.
     * If  you want to delete a node and all of it's children, @see delete_node_no_children() instead
	 * @param array $node
	 * @throws Exception
	 * @return boolean
	 */
	private function delete_node_keep_children($node){
		try	{
			$this->_db->beginTransaction();
				
			$sql = "delete from `".$this->table_name."` where `".$this->_id."` = ".$node[0][$this->_id];
			$this->_db->exec($sql);
				
			$sql = "update `".$this->table_name."` SET `".
				$this->right_col."` = `".$this->right_col."` - 1,  `".
				$this->left_col."` = `".$this->left_col."` - 1 where (`".$this->left_col."` BETWEEN ".$node[0][$this->left_col]." AND ".$node[0][$this->right_col].')';
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
			
			$sql = "update `".$this->table_name."` SET `".$this->right_col."` = `".$this->right_col."` - 2 where `".$this->right_col."` > ".$node[0][$this->right_col];
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
			
			$sql = "update `".$this->table_name."` SET `".$this->left_col."` = `".$this->left_col."` - 2 where `".$this->left_col."` > ".$node[0][$this->right_col];
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
					
			$this->_db->commit();
		}
		catch(Exception $e)	{
			$this->_db->rollBack();
			throw new Exception('Error in add_node : '.$e->getMessage());
		}
		
		return true;
	}
	
	/**
	 * Deletes a node from the tree and re-orders the tree. 
	 * If this node has children, they will also be deleted. Use @see delete_node_keep_children()
     * if you don't want to delete the child nodes.
	 * 
	 * @param array $node
	 * @throws Exception
	 * @return boolean
	 */
	private function delete_node_no_children($node){
		try	{
			$this->_db->beginTransaction();
			$diff = ($node[0][$this->right_col] - $node[0][$this->left_col]) + 1;
			
			$sql = "delete from `".$this->table_name."` where (`".$this->left_col."` BETWEEN ".$node[0][$this->left_col]." AND ".$node[0][$this->right_col].')';
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
			
			$sql = "update `".$this->table_name."` SET `".$this->right_col."` = `".$this->right_col."` - $diff where `".$this->right_col."` > ".$node[0][$this->right_col];
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
			
			$sql = "update `".$this->table_name."` SET `".$this->left_col."` = `".$this->left_col."` - $diff where `".$this->left_col."` > ".$node[0][$this->right_col];
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
		
			$this->_db->commit();
		}
		catch(Exception $e)	{
			$this->_db->rollBack();
			throw new Exception('Error in add_node : '.$e->getMessage());
		}

		return true;
	}
	
	private function get_full_tree(){
		$sql = "SELECT * FROM `".$this->table_name."`";
		$where=$this->get_tree_grp_where();
		if(!empty($where)){
			$sql.=" WHERE true $where"; 
		}
		$sql.=" ORDER BY `".$this->left_col."` ASC; ";
		return $this->_db->fetchAll($sql);
	}
	
	private function get_tree($lft, $rgt){
		$sql = "SELECT * FROM `".$this->table_name."` WHERE (`".$this->left_col."` BETWEEN {$lft} AND {$rgt} )";
		$sql.=$this->get_tree_grp_where();
		$sql.=" ORDER BY `".$this->left_col."` ASC; ";
		return $this->_db->fetchAll($sql);
	}
	
	private function create_root_node(){
		// Tree is empty, create the root node
		$sql = "INSERT INTO `".$this->table_name."` SET `".$this->_name."` = '".$this->root."', `".$this->left_col."` = 1, `".$this->right_col."` = 2";
		$group_col = $this->get_tree_grp_col();
		if(!empty($group_col)){
			$group_col_val = $this->get_tree_grp_col_val();
			if(!empty($group_col_val)){
				$sql.=" , `{$group_col}` = '{$group_col_val}'";
			}
		}
		$sql.=';';
		$this->_db->exec($sql);
	}
	
	private function insert_node($coords){
		try	{
			$this->_db->beginTransaction();
			// Make room in the tree for the new node
			$sql = "update `".$this->table_name."` SET `".$this->left_col."` = `".$this->left_col."` + 2 where `".$this->left_col."` >= ".$coords[$this->left_col];
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
			$sql = "update `".$this->table_name."` SET `".$this->right_col."` = `".$this->right_col."` + 2 where `".$this->right_col."` >= ".$coords[$this->left_col];
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
	
			$sql = "update `".$this->table_name."` SET `".
					$this->left_col."` = ".$coords[$this->left_col].", `".
					$this->right_col."` = ".$coords[$this->right_col]." where `".$this->_id."` = ".$this->id;
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
	
			$this->_db->commit();
		}
		catch(Exception $e)	{
			$this->_db->rollBack();
			throw new Exception('Error in add_node : '.$e->getMessage());
		}
	}

	/**
	 * Returns name and depth of nodes relative to parent node. The first element in the result is the parent node name and depth
	 * @param Array<representation of node> $parent
	 * @return Array < Array<String node_name, int depth> >
	 */
	private function get_immediate_children($parent){
		$grp_sql='';
		$group_col = $this->get_tree_grp_col();
		if(!empty($group_col)){
			$group_col_val = $this->get_tree_grp_col_val();
			if(!empty($group_col_val)){
				$grp_sql="`{$group_col}` = '{$group_col_val}'";
			}
		}

		$sql = " SELECT node.".$this->_name.", (COUNT(parent.".$this->_name.") - (sub_tree.depth + 1)) AS depth
		FROM `".$this->table_name."` AS node
		  JOIN `".$this->table_name."` AS parent ON (node.".$this->left_col." BETWEEN parent.".$this->left_col." AND parent.".$this->right_col;
		$sql.=(empty($grp_sql)) ? ")" : " AND parent.{$grp_sql})";
		$sql.="JOIN `".$this->table_name."` AS sub_parent ON (node.".$this->left_col." BETWEEN sub_parent.".$this->left_col." AND sub_parent.".$this->right_col;
		$sql.=(empty($grp_sql)) ? ")" : " AND sub_parent.{$grp_sql})";

		$sql.=" JOIN (
				SELECT node.".$this->_name.", (COUNT(parent.".$this->_name.") - 1) AS depth
				FROM `".$this->table_name."` AS node JOIN `".$this->table_name."` AS parent ON node.".$this->left_col." BETWEEN parent.$this->left_col AND parent.".$this->right_col."
				WHERE node.".$this->_name." = '".$parent['name']."'";

		if(!empty($grp_sql)) $sql.=" AND `node`.{$grp_sql}";

		$sql.="		GROUP BY node.".$this->_name."
				ORDER BY node.".$this->left_col."
			)AS sub_tree ON sub_parent.".$this->_name." = sub_tree.".$this->_name;

        $sql.=(empty($grp_sql)) ? "" : " WHERE (node.{$grp_sql})";

		$sql.="GROUP BY node.".$this->_name."
		HAVING depth <= 1
		ORDER BY node.".$this->left_col."";

		return $this->_db->fetchAll($sql);
	}

	/**
	 * Make room in the tree for a node move. If sibling_move is '', assumes we are moving a node to a new parent. 
	 * Where it is 'up' or 'down', we are moving a nodes position within it's same parent, eg move node before current sibling.
	 * 'up' is for case of node moving to left of tree (i.e. lft and rgt values decreasing)
	 * 'down' is for case of node moving to right of tree (i.e. lft and rgt values increasing)
	 * 
	 * @param  int  $start Start position for the move
	 * @param  int $delta Size of the move (tree size)
	 * @param  boolean $sibling_move '' (default) to move node to new parent, 'up' or 'down' to move nodes position amongst its siblings
     * @throws Exception
	 */
	private function shift_values($start, $delta, $sibling_move = ''){
		try	{
			$this->_db->beginTransaction();
			$st = $start;

			switch ($sibling_move) {
				case '':
					break;
				case 'up':
					$st = $start - 1;
					break;
				case 'down':
					// nothing to do yet, need to adjust $st after first query
					break;
			}

			$sql = "update `".$this->table_name."` SET `".$this->left_col."` = `".$this->left_col."` + $delta where `".$this->left_col."` > $st";
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);

			switch ($sibling_move) {
				case '':
					break;
				case 'up':
					$st = $start;
					break;
				case 'down':
					$st = $start + 1;
					break;
			}

			$sql = "update `".$this->table_name."` SET `".$this->right_col."` = `".$this->right_col."` + $delta where `".$this->right_col."` >= $st";
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
		
			$this->_db->commit();
		}
		catch(Exception $e)	{
			$this->_db->rollBack();
			throw new Exception('Error in shift_values : '.$e->getMessage());
		}
	}
	
	private function shift_range($l, $r, $delta){
		try	{
			$this->_db->beginTransaction();
			$sql = "update `".$this->table_name."` SET `".$this->left_col."` = `".$this->left_col."` + $delta where (`".$this->left_col."` >= $l and `".$this->left_col."` <= $r)";
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
			$sql = "update `".$this->table_name."` SET `".$this->right_col."` = `".$this->right_col."` + $delta where (`".$this->right_col."` >= $l and `".$this->right_col."` <= $r)";
			$sql.=$this->get_tree_grp_where();
			$this->_db->exec($sql);
		
			$this->_db->commit();
		}
		catch(Exception $e)	{
			$this->_db->rollBack();
			throw new Exception('Error in shift_range : '.$e->getMessage());
		}
	}
	
	public function get_parent_id(){
		$l = $this->left_col;
		$r = $this->right_col;
		
		$sql = "SELECT `".$this->_id."` FROM `".$this->table_name."` WHERE (`".
			$this->left_col."` < ".$this->$l." and `".$this->right_col."` > ".$this->$r.")";
		$sql.=$this->get_tree_grp_where();
		$sql.=" order by `".$this->left_col."` desc ";
		$res = $this->_db->fetchAll($sql);
	
		if(empty($res)){
			trigger_error("Could not find parent node of node ".$this->id);
			return null;
		}
	
		return $res[0]['id'];
	}

	public function get_prev_sibling_id(){
		$l = $this->left_col;
		
		$sql = "SELECT `".$this->_id."` FROM `".$this->table_name."` WHERE `".$this->right_col."` = (".$this->$l." - 1) ";
		$sql.=$this->get_tree_grp_where();
		$res = $this->_db->fetchAll($sql);
	
		if(empty($res)){
            trigger_error("Could not find previous sibling of node ".$this->id);
			return null;
		}
	
		return $res[0]['id'];
	}

	public function get_next_sibling_id(){
		$r = $this->right_col;
		
		$sql = "SELECT `".$this->_id."` FROM `".$this->table_name."` WHERE `".$this->left_col."` = (".$this->$r." + 1) ";
		$sql.=$this->get_tree_grp_where();
		$res = $this->_db->fetchAll($sql);
	
		if(empty($res)){
            trigger_error("Could not find next sibling of node ".$this->id);
			return null;
		}
	
		return $res[0]['id'];
	}
	
	private static function calc_number_children($lft, $rgt){
		return ($rgt - $lft - 1) / 2;
	}

}
?>