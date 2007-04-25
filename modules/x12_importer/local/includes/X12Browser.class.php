<?php
$loader->requireOnce('includes/X12BlockBrowser.class.php');

/**
 * A browser for the x12 object tree generated by {@link X12MapParser}
 *
 * @package com.uversainc.x12
 */
class X12Browser
{
	/**#@+
	 * @access private
	 */
	var $_tree = null;
	/**#@-*/
	
	
	/**
	 * Sets the tree that we are working with. 
	 */
	function setTree(&$tree) {
		$this->_tree =& $tree;
	}
	
	
	/**
	 * Return a block of code by a given path
	 *
	 * @param  string  $code
	 * @return object
	 */
	function getBlockByPath($path) {
		if (substr($path, 0, 1) == '/') {
			$path = substr($path, 1);
		}
		
		$explodedPath = explode('/', $path);
		$finalLeaf = array_pop($explodedPath);
		$currentBranch = $this->_tree;
		if (count($explodedPath) > 0) {
			foreach ($explodedPath as $currentLevelPath) {
				if (is_array($currentBranch[$currentLevelPath][0]) && isset($currentBranch[$currentLevelPath][0][0])) {
					$currentBranch = $currentBranch[$currentLevelPath][0][0];
				}
				elseif (isset($currentBranch[$currentLevelPath][0])) {
					$currentBranch = $currentBranch[$currentLevelPath][0];
				}
				elseif (isset($currentBranch[$currentLevelPath])) {
					$currentBranch = $currentBranch[$currentLevelPath];
				}
				else { 
					return false;
				}
			}
		}
		elseif (!isset($currentBranch[$finalLeaf])) {
			return false;
		}
		
		// address case where we jump out of the array
		if (is_object($currentBranch)) {
			$currentBranch = array($currentBranch);
		}
		return $this->_parseBranch($currentBranch, $finalLeaf);
	}
	
	function _parseBranch($currentBranch, $finalLeaf) {
		foreach ($currentBranch as $currentLeaf) {
			if (is_array($currentLeaf)) {
				$return = $this->_parseBranch($currentLeaf, $finalLeaf);
				if ($return !== false) {
					return $return;
				}
			}
			else {
				if ($currentLeaf->code == $finalLeaf) {
					return new X12BlockBrowser($currentLeaf);
				}
			}
		}
		return false;
	}
}

?>