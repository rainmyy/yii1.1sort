<?php
/**
 * @title  对列表进行上下排序
 * @author chenzhenghao
 * @date   2015-07-09
 */
class Sorts{
	public static $code=0;
	public static $message='';
	public static $falseFlag=0;
   
   /**
   * @title       检测手动输入的where值
   * @param where 条件array('字段'=>'值', '字段2'=> '值2', 'wherein' => 'in 语句', 'between字段' => array('初值','终值'))
   */
	public static function checkWhere($where){
		
		$special = '';

		if($where == '') return;
		
		if(!is_array($where)) return;
        
		$str=$whereData=$returnStr=array();

		foreach ($where AS $key => $val) {

			if ($key != 'wherein') {
				if (!is_array($val)) {
					$str[] = $key.' =:'.$key;
					$whereData[':'.$key]=$val;
				} elseif(is_array($val) && count($val) == 2) {
					$str[] = $key .' between '. $val[0] .' AND '. $val[1];
				} elseif(is_array($val) && count($val) > 2){
					$str[] = $key .' in ('.implode(',', $val) .')';
				} elseif(isset($where['wherein'])) {
						$special = ' AND ' .$where['wherein'];
				}
			}
		}

		 $str = implode(' and ', $str);
		 
		 if (isset($where['wherein'])) {
			$str .= $special;
		 }
		 
		 return $returnStr = array($str, $whereData);
	}

	/**
	 * @title         更改排列顺序
	 * @param  id     需要操作的数据的ID
	 * @param  action 向上向下的操作 down=向下，up=向上
	 * @param  order  数据库中查询按照order字段进行排序
	 * @param  table  数据表的名称
	 * @param where 条件array('字段'=>'值', '字段2'=> '值2', 'wherein' => 'in 语句', 'between字段' => array('初值','终值'))
	 * @return array  'code'操作码 'message'操作信息
	 */    
	public static function changeSorts($id = 0, $action = 'up', $order = 'order', $table = '', $where = '') {
		$code    = self::$code;
		$message = self::$message;

		if (empty($id)) {

			$code    = 10001;
			$message = '无效ID';

			return array('code' => $code, 'message' => $message);
		}

		if ($action == '') {

			$code    = 10001;	  
			$message = '无效操作';

			return array('code' => $code, 'message' => $message);
		}

		if ($order == '') {

			$code    = 10001;
			$message = '排序字段不能为空';

			return array('code' => $code, 'message' => $message);
		}
        
		$connect  = Yii::app()->db;
		$theOrder = $connect->createCommand()->select($order)->from($table)->where('id = :id AND is_del = :is_del',array(':id' => $id, ':is_del' => DEL_N))->queryRow();
        
		if (!$theOrder) {
			$code    = 10002;
			$message = '无效的排序字段';

			return array('code' => $code, 'message' => $message);
		}

		if ($action == 'up') {

			$firstOrder = $connect->createCommand()->select($order)->from($table)->where('is_del = :is_del', array(':is_del' => DEL_N));    
        
		    if ($where) {
			  
			  $strWhere = self::checkWhere($where);
			  $firstOrder->andWhere($strWhere[0], $strWhere[1]);
			}
            
			$firstOrder = $firstOrder->order($order.' ASC')->queryRow();
			
			if ($theOrder[$order] == $firstOrder[$order]) {
				$code    = 10002;
				$message = '数据无法操作';

				return array('code' => $code, 'message' => $message);

			}

			$condition = '`'.$order.'`< :order AND is_del=:is_del';
			$orderBy   = $order.' DESC';

		} elseif ($action == 'down') {

			$finalOrder = $connect->createCommand()->select($order)->from($table)->where('is_del=:is_del', array(':is_del' => DEL_N));	
			
			if ($where) {
			  $strWhere = self::checkWhere($where);
			  $finalOrder->andWhere($strWhere[0], $strWhere[1]);
			}

			$finalOrder = $finalOrder->order($order.' DESC')->queryRow();

			if ($theOrder[$order] == $finalOrder[$order]) {

				$code    = 10002;
				$message = '数据无法操作';

				return array('code' => $code, 'message' => $message);
			}

			$condition = '`'.$order.'` > :order AND is_del = :is_del';
			$orderBy   = $order.' ASC';

		}

		$param  = array(':order' => $theOrder[$order], ':is_del' => DEL_N);
		$orders = $connect->createCommand()->select('*')->from($table)->where($condition, $param);
         
	    if ($where) {
		   $strWhere = self::checkWhere($where);
		   $orders ->andWhere($strWhere[0], $strWhere[1]);
		}
		
		$orders = $orders->order($orderBy)->queryRow();

		if (!empty($orders)) {

			$transaction = $connect->beginTransaction();
			try {

				$modified = time();

				$connect->createCommand('UPDATE `'.$table.'` SET `'.$order.'` = '.$orders[$order].', modified = '.$modified.' WHERE id = '.$id.'')->execute();
				$connect->createCommand('UPDATE `'.$table.'` SET `'.$order.'` = '.$theOrder[$order].' , modified = '.$modified.' WHERE id = '.$orders['id'].'')->execute();
				$transaction->commit();
				$message='修改成功';

			} catch(Exception $e) {

				$code = 10001;
				$message = $transaction->rollBack();
			}
		} else {
		  $code = 10002;
		  $message = '数据无法操作';
		}
		return array('code' => $code, 'message' => $message);
	}

	/**
	 * @title         手动插入排序的操作
	 * @note          该功能需放在插入数据的操作之前，否则会将插入的序号更新掉
	 * @param  sortId 手动输入的插入排序序号
	 * @param  order  数据表中排序的字段
	 * @param  table  数据表的名称
	 * @param where 条件array('字段'=>'值', '字段2'=> '值2', 'wherein' => 'in 语句', 'between字段' => array('初值','终值'))
	 */
	public static function changeInsert($sortId = 0, $order = 'order', $table = '', $where = '') {
		$code      = self::$code;
		$falseFlag = self::$falseFlag;
		$message   = self::$message;
		$id = array();

		if (empty($sortId) OR $sortId<=0) {
			$code=10001;
			$message='无效的序号';
			$falseFlag=1;
		}

		if ($order == '') {
			$code    = 10001;
			$message = '无效的排序字段';
			$falseFlag = 1;
		}

		if ($table == '') {
			$code    = 10001;
			$message = '无效的表名';
			$falseFlag = 1;
		}

		if ($falseFlag == 1) {

			return array('code' => $code, 'message' => $message);
		} else {

			$command = Yii::app()->db->createCommand();
			$Orders  = $command->select('*')->from($table)->where(' `'.$order.'` >= :sortId AND is_del = :is_del', array(':sortId' => $sortId, ':is_del' => DEL_N));
			
			if ($where) {
				$strWhere = self::checkWhere($where);
                $Orders->andWhere($strWhere[0], $strWhere[1]);
			}
			
			$Orders =  $Orders->order($order.' ASC')->query();
			$Orders  = $Orders->readAll(); 

			if (empty($Orders)) {

				$message = '数据无需修改';
				return array('code' =>0, 'message'=>$message);
			} else {

			    foreach($Orders AS $key => $val) {
					$OrderId[] = $val[$order];
				}

				if(!in_array($sortId,$OrderId)) {
					$message = '数据无需修改';
					return array('code' =>0, 'message'=>$message);
				}
				
				$modified = time();
				$i = 0;

				foreach ($Orders AS $val){
					$id[]      = $val['id'];
				}
				
				$count = $command->update($table, array($order => new CDbExpression($order. '+ 1'), 'modified' => $modified), 'id IN ('.implode(',',$id).') AND is_del = :is_del', array(':is_del' => DEL_N));

				if ($count >= 1) {
					$message = '插入成功';
				} else {
					$code    = 10001;
					$message = '插入失败';
				}

				return array('code' => $code, 'message' => $message);
			}
		}
	}

	/**
	 * @title       删除改变排列序号
	 * @param id    手动输入的插入排序序
	 * @param order 数据表中排序的字段
	 * @param table 数据表的名称 
	 * @param where 条件array('字段'=>'值', '字段2'=> '值2', 'wherein' => 'in 语句', 'between字段' => array('初值','终值'))
	 */
	public static function changeDel($id = 0, $order = 'order', $table = '', $where = ''){

		$code      = self::$code;
		$message   = self::$message;
		$falseFlag = self::$falseFlag;

		if (empty($id) OR $id == 0) {

			$code      = 10001;
			$message   = '无效的ID';
			$falseFlag = 1;
		}

		if (empty($order)) {

			$code      = 10001;
			$message   = '无效的字段名称';
			$falseFlag = 1;
		}

		if (empty($table)) {

			$code      = 10001;
			$message   = '无效的表名';
			$falseFlag = 1;
		}

		if ($falseFlag == 1) {

			return array('code' => $code, 'message' => $message);
		} else {

			$command = Yii::app()->db;

			$OrderId = $command->createCommand()->select($order)->from($table)->where('id = :id AND is_del = :is_del',array(':id' => $id, ':is_del' => DEL_N));
			
			if($where){
				$strWhere = self::checkWhere($where);
				$orderId->andWhere($strWhere[0], $strWhere[1]);
			}
            $orderId = $OrderId->queryRow();

			if (empty($orderId)){

				$code    = 10002;
				$message = '无效的排序字段';

				return array('code' => $code, 'message' => $message);
			}

			$orderId = $orderId[$order];
			//$Orders  = $command->select('*')->from($table)->where(' `'.$order.'` >= :sortId AND is_del = :eis_del', array(':sortId' => $orderId, ':eis_del' => DEL_N))->order($order.' ASC')->query();
			$Orders  = $command->createCommand()->select('*')->from($table)->where(' `'.$order.'` >= :sortId AND is_del = :eis_del', array(':sortId' => $orderId, ':eis_del' => DEL_N));
			
			if ($where) {
				$strWhere = self::checkWhere($where);
				$Orders->andWhere($strWhere[0], $strWhere[1]);
			}

			$Orders = $Orders->order($order.' ASC')->query();
			$Orders  = $Orders->readAll();

			if (empty($Orders)) {
				$message ='数据已达最低端，无需修改';
				return array('code'=>$code, 'message'=>$message);
			} else {

				$modified = time();
				$i        = 0;

				foreach ($Orders AS $val) {

					$id      = $val['id'];
					$orderId = $val[$order];
    
					if ($orderId > 1){

						$count   = $command->createCommand()->update($table, array($order => $orderId-1, 'modified' => $modified ), 'id = :eid AND is_del = :ais_del', array(':eid' => $val['id'], ':ais_del' => DEL_N));

						if ($count) $i ++ ;
					}
				}

				if ($i >0){
					$message = '删除成功';
					return array('code' => $code, 'message' => $message);
				}else{
					$code    = 10001;
					$message = '删除失败';
					return array('code' => $code, 'message' => $message);
				}
			}

		}
	}

}

