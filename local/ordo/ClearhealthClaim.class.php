<?php
/**
 * Object Relational Persistence Mapping Class for table: claim
 *
 * @package	com.uversainc.clearhealth
 * @author	Joshua Eichorn <jeichorn@mail.com>
 */


/**
 * Object Relational Persistence Mapping Class for table: claim
 *
 * @package	com.uversainc.clearhealth
 */
class ClearhealthClaim extends ORDataObject {

	/**#@+
	 * Fields of table: claim mapped to class members
	 */
	var $id			= '';
	var $encounter_id	= '';
	var $identifier		= '';
	var $total_billed	= '';
	var $total_paid		= '';
	/**#@-*/
	var $_table = 'clearhealth_claim';
	var $_internalName='ClearhealthClaim';

	/**
	 * Stores the cached version the copay total generated by
	 * {@link value_copay_total()}.
	 *
	 * @var string
	 * @access private
	 */
	var $_copayTotal = null;
	
	
	/**
	 * Setup some basic attributes
	 * Shouldn't be called directly by the user, user the factory method on ORDataObject
	 */
	function ClearhealthClaim($db = null) {
		parent::ORDataObject($db);	
		$this->_sequence_name = 'sequences';	
	}

	/**
	 * Called by factory with passed in parameters, you can specify the primary_key of ClearhealthClaim with this
	 */
	function setup($id = 0) {
		if ($id > 0) {
			$this->set('id',$id);
			$this->populate();
		}
	}

	function setupByIdentifier($ident) {
		$sql = "select * from ".$this->tableName()." where identifier = ".$this->dbHelper->quote($ident);
		$res = $this->dbHelper->execute($sql);
		$this->helper->populateFromResults($this,$res);
	}

	/**
	 * A custom setup method to handle getting a ClearhealthClaim based on an encounter ID
	 *
	 * @param  int
	 * @access protected
	 */
	function setupByEncounterId($encounterId) {
		$qEncounterId = $this->dbHelper->quote($encounterId);
		$tableName = $this->tableName();
		$sql = "SELECT * FROM {$tableName} WHERE encounter_id = {$qEncounterId}";
		$this->helper->populateFromQuery($this, $sql);
	}
	
	
	function &fromEncounterId($encounter_id) {
		settype($encounter_id,'int');

		$claim =& Celini::newORDO('ClearhealthClaim');
		
		$res = $claim->dbHelper->execute("select claim_id from ".$claim->tableName()." where encounter_id = $encounter_id");
		if ($res && isset($res->fields['claim_id'])) {
			$claim->setup($res->fields['claim_id']);
		}
		return $claim;

	}
	
	function summedPaymentsByCode() {
		$sql = "
			select
				codes.code,
				sum(pc.paid) paid,
				sum(pc.writeoff) writeoff,
				sum(pc.carry) carry 
			from
				payment_claimline pc
				left join codes using(code_id)
				inner join payment p on pc.payment_id = p.payment_id 
			where
				foreign_id = ".(int)$this->get('id') . " 
			group by pc.code_id ";
		$res = $this->dbHelper->execute($sql);
		$ret = array();
		while ($res && !$res->EOF) {
			$ret[$res->fields['code']] = $res->fields;
			$res->moveNext();
		}
		return $ret;
	}
	
	function summedPaymentsByCodingData() {
		$sql = "
			select
				cd.coding_data_id,
				codes.code,
				sum(pc.paid) paid,
				sum(pc.writeoff) writeoff,
				sum(pc.carry) carry 
			from
				payment_claimline pc
				LEFT JOIN coding_data cd ON(cd.coding_data_id=pc.coding_data_id)
				left join codes ON(cd.code_id=codes.code_id)
				inner join payment p on pc.payment_id = p.payment_id 
			where
				p.foreign_id = ".(int)$this->get('id') . " 
			group by pc.coding_data_id ";
		$res = $this->dbHelper->execute($sql);
		$ret = array();
		while ($res && !$res->EOF) {
			$ret[$res->fields['coding_data_id']] = $res->fields;
			$res->moveNext();
		}
		return $ret;
	}

	/**
	 * Get datasource for claims from the db
	 *
	 * @todo Move to local/datasources/ and create its own DS file
	 */
	function &claimList($patient_id,$show_lines = false,$filters = false) {
		settype($foreign_id,'int');

		$where = "";
		if (is_array($filters)) {
			foreach ($filters as $fname => $fval) {
			if  (!empty($fval)) {
				switch ($fname) {
					case 'status':
						$where .= " c.status = " . $this->_quote($fval) . " and ";
						break;
					case 'start':
						$where .= " UNIX_TIMESTAMP(e.date_of_treatment) > " . $this->_quote(strtotime($fval)) . " and ";
						break;
					case 'end':
						$where .= " UNIX_TIMESTAMP(e.date_of_treatment) < " . $this->_quote(strtotime($fval)) . " and ";
						break;
					case 'facility':
						$where .= " (o.location_id = " . $this->_quote($fval) . " or e.building_id = ".$this->_quote($fval).") and ";
						break;
					case 'provider':
						$where .= " e.treating_person_id = ". (int)$fval . " and ";
						break;
					case 'payer':
						$where .= " pa.payer_id =  " . (int)$fval. " and ";
						break;
					case 'active':
						if ($fval == 1) {
							$where .= " c.status != 'closed' and ";
						}
						break;
					case 'claim_identifier':
							$where .= " c.claim_identifier like " . $this->_quote($fval."%") . " and ";
						break;
					case 'user':
							$qUser = enforceType::int($fval);
							$where .= " u.user_id = $qUser and ";
						break;
					default:
						break;
				}	
			}
			}
		}
		$where = substr($where,0,-4);
		if (strlen($where) > 0) {
			$where = " and $where";
		}
		
		if ($foreign_id == 0) $foreign_id = "NULL";

		$format = DateObject::getFormat();
		
		$ds =& new Datasource_sql();
		$ds->setup($this->_db,array(
				'cols' 	=> "
					chc.claim_id, 
					chc.identifier,
					if (fbc.date_sent = '0000-00-00 00:00:00','Not Sent',date_format(fbc.date_sent, '$format')) AS billing_date,
					date_format(e.date_of_treatment,'$format') AS date_of_treatment, 
					chc.total_billed,
					chc.total_paid,
					fbco.name AS current_payer,
					b.name facility,
					concat_ws(',',pro.last_name,pro.first_name) AS provider,
					(chc.total_billed - chc.total_paid - SUM(IFNULL(pcl.writeoff,0))) AS balance, 
					SUM(IFNULL(pcl.writeoff,0)) AS writeoff,
					u.username user",
				'from' 	=> 
					$this->_table . ' AS chc 
					INNER JOIN encounter AS e USING(encounter_id)
					LEFT JOIN payment AS pa ON(pa.foreign_id = chc.claim_id)
					LEFT JOIN payment_claimline AS pcl ON(pcl.payment_id = pa.payment_id)
					LEFT JOIN occurences AS o ON(e.occurence_id = o.id)
					LEFT JOIN buildings AS b ON(e.building_id = b.id)
					LEFT JOIN person AS pro ON(e.treating_person_id = pro.person_id)
					LEFT JOIN fbclaim AS fbc ON(chc.identifier = fbc.claim_identifier)
					LEFT JOIN fblatest_revision AS fblr ON(fblr.claim_identifier = fbc.claim_identifier)
					LEFT JOIN fbcompany AS fbco ON(fbc.claim_id = fbco.claim_id AND fbco.type = "FBPayer" AND fbco.index = 0)
					LEFT JOIN user AS u ON(e.created_by_user_id = u.user_id)
					',
				'where' => ' (fblr.revision = fbc.revision) and e.patient_id = ' . $patient_id . $where,
				'groupby' => 'chc.claim_id'
			),
			array(
				'identifier' => 'Id',
				'billing_date' => 'Billing Date',
				'date_of_treatment' => 'Date', 
				'total_billed' => 'Billed',
				'total_paid' => 'Paid',
				'balance' => 'Balance',
				'current_payer' => 'Payer Name'
			)
		);
		$ds->orderHints['billing_date'] = 'fbc.date_sent';
		$ds->orderHints['date_of_treatment'] = 'concat(e.date_of_treatment, e.encounter_id)';
		//echo $ds->preview();
		return $ds;
	}

	/**
	 * Return the total amount of all of the co-pays associated with this claim
	 *
	 * @return string
	 * @access protected
	 * @see $_copayTotal
	 */
	function value_copay_total() {
		if (is_null($this->_copayTotal)) {
			$qEncounterId = $this->dbHelper->quote($this->get('encounter_id'));
			$sql = "SELECT ifnull(SUM(ifnull(amount,0)),0) FROM payment WHERE encounter_id = {$qEncounterId}";
			$this->_copayTotal += $this->dbHelper->getOne($sql);
		}
		return $this->_copayTotal;
	}
	
	/**
	 * Populate the class from the db
	 */
	function populate() {
		parent::populate('claim_id');
	}

	/**#@+
	 * Getters and Setters for Table: claim
	 */

	
	/**
	 * Getter for Primary Key: claim_id
	 */
	function get_claim_id() {
		return $this->id;
	}

	/**
	 * Setter for Primary Key: claim_id
	 */
	function set_claim_id($id)  {
		$this->id = $id;
	}

	/**
	 * Get array of adjustment data (not ORDOs)
	 */
	function get_adjustments() {
		$sql = "
		SELECT
			ea.*,codes.code
		FROM
			clearhealth_claim cc
			LEFT JOIN payment p ON(p.foreign_id=cc.claim_id)
			LEFT JOIN payment_claimline pc USING(payment_id)
			INNER JOIN eob_adjustment ea ON(ea.payment_id=p.payment_id OR ea.payment_claimline_id = pc.payment_claimline_id)
			LEFT JOIN coding_data cd ON(cd.coding_data_id=pc.coding_data_id)
			LEFT JOIN codes ON(codes.code_id=cd.code_id)
		WHERE
			cc.claim_id = ".$this->dbHelper->quote($this->get('id'))."
		GROUP BY
			ea.eob_adjustment_id";
		$res = $this->dbHelper->execute($sql);
		$adjustments = array();
		while($res && !$res->EOF) {
			$adjustments[] = $res->fields;
			$res->MoveNext();
		}
		return $adjustments;
	}
	
	/**#@-*/
}
?>
