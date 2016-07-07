<?php
/**
 * @title  对列表进行上下排序
 * @author chenzhenghao
 * @date   2015-07-09
 */
namespace common\library;

use	Yii;
use yii\db\Query;
use yii\helpers\Json;

class Sorts{
	public static $code      = 200;
	public static $message   = '';
	public static $falseFlag = 0;
   
   /**
   * @title       检测手动输入的where值
   * @param where 条件array('字段'=>'值', '字段2'=> '值2', 'wherein' => 'in 语句', 'between字段' => array('初值','终值'))
   */
	public static function checkWhere($where){
		
		if($where == '') return;
		
		if(!is_array($where)) return;
        
		$str=$whereData=$returnStr=[];

		foreach ($where AS $key => $val) {

			if ($key != 'wherein') {
				if (!is_array($val)) {
					$str[] = $key.' = :'.$key;
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
			return RenderMessage::get(10001, '无效ID');
		}

		if ($action == '') {
			return RenderMessage::get(10001, '无效操作');
		}

		if ($order == '') {
			return RenderMessage::get(10001, '排序字段不能为空');
		}
        
		$connect  = (new Query());
		$theOrder = $connect->select([$order])->from($table)->where('id = :id AND is_del = :is_del',array(':id' => $id, ':is_del' => Yii::$app->params['DEL_N']))->one();
		
		if (!$theOrder) {
			return RenderMessage::get(10001, '无限的排序字段');
		}
		
		if ($action == 'up') {

			$firstOrder = (new Query())->select([$order])->from($table)->where('is_del = :is_del', [':is_del' => Yii::$app->params['DEL_N']]);    
		    
			if ($where) {
			  
			  $strWhere = self::checkWhere($where);
			  $firstOrder->andWhere($strWhere[0], $strWhere[1]);
			}
			
			$firstOrder = $firstOrder->orderBy($order.' ASC')->one();
			
			if ($theOrder[$order] == $firstOrder[$order]) {
				return RenderMessage::get(10002, '数据无法操作');
			}

			$condition = '`'.$order.'`< :order AND is_del=:is_del';
			$orderBy   = $order.' DESC';

		} else {

			$finalOrder = (new Query())->select([$order])->from($table)->where('is_del=:is_del', [':is_del' => Yii::$app->params['DEL_N']]);	
			
			if ($where) {
			  $strWhere = self::checkWhere($where);
			  $finalOrder->andWhere($strWhere[0], $strWhere[1]);
			}
			
			$finalOrder = $finalOrder->orderBy($order.' DESC')->one();

			if ($theOrder[$order] == $finalOrder[$order]) {

				$code    = 10002;
				$message = '数据无法操作';
				return RenderMessage::get(10002, '数据无法操作');
			}

			$condition = '`'.$order.'` > :order AND is_del = :is_del';
			$orderBy   = $order.' ASC';

		}
		
		$param  = array(':order' => $theOrder[$order], ':is_del' => Yii::$app->params['DEL_N']);
		$orders = (new Query())->select(['*'])->from($table)->where($condition, $param);
         
	    if ($where) {
		   $strWhere = self::checkWhere($where);
		   $orders ->andWhere($strWhere[0], $strWhere[1]);
		}
		
		$orders = $orders->orderBy($orderBy)->one();
		
		if (!empty($orders)) {
			$connect = \Yii::$app->db;
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

		return RenderMessage::get($code, $message);
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
			return RenderMessage::get(10001, '无效的排序号');
		}

		if ($order == '') {
			return RenderMessage::get(10001, '无效的排序字段');
		}

		if ($table == '') {
			return RenderMessage::get(10001, '无效的表名');
		}

		$Orders  = (new Query())->select('*')->from($table)->where(' `'.$order.'` >= :sortId AND is_del = :is_del', [':sortId' => $sortId, ':is_del' => Yii::$app->params['DEL_N']]);
			
		if ($where) {
			$strWhere = self::checkWhere($where);
            $Orders->andWhere($strWhere[0], $strWhere[1]);
		}
			
		$Orders =  $Orders->orderBy($order.' ASC')->All();

		if (empty($Orders)) {
			return RenderMessage::get(10002, '数据无需修改');
		} else {

			foreach($Orders AS $key => $val) {
				$OrderId[] = $val[$order];
			}

			if(!in_array($sortId,$OrderId)) {
				return RenderMessage::get(10002, '数据无需修改');
			}
				
			$modified = time();
			$i = 0;

			foreach ($Orders AS $val){
				$id[] = $val['id'];
			}
				
			$count = (new Query())->update($table, array($order => new \yii\db\Expression($order. '+ 1'), 'modified' => $modified), 'id IN ('.implode(',',$id).') AND is_del = :is_del', [':is_del' => DEL_N]);

			if ($count >= 1) {
				$message = '插入成功';
			} else {
				$code    = 10001;
				$message = '插入失败';
			}
			
			return RenderMessage::get($code, $message);
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
			return RenderMessage::get(10001, '无效的ID');
		}

		if (empty($order)) {
			return RenderMessage::get(10001, '无效的字段名称');
		}

		if (empty($table)) {
			return RenderMessage::get(10001, '无效的表名');
		}


		$OrderId = (new Query())->select($order)->from($table)->where('id = :id AND is_del = :is_del', [':id' => $id, ':is_del' => Yii::$app->params['DEL_N']]);
			
		if($where){
			$strWhere = self::checkWhere($where);
			$orderId->andWhere($strWhere[0], $strWhere[1]);
		}
        
		$orderId = $OrderId->one();

		if (empty($orderId)){
			return RenderMessage::get(10002 ,'无效的排序字段');
		}

		$orderId = $orderId[$order];
		//$Orders  = $command->select('*')->from($table)->where(' `'.$order.'` >= :sortId AND is_del = :eis_del', array(':sortId' => $orderId, ':eis_del' => DEL_N))->order($order.' ASC')->query();
		$Orders  = (new Query())->select('*')->from($table)->where(' `'.$order.'` >= :sortId AND is_del = :eis_del', [':sortId' => $orderId, ':eis_del' => Yii::$app->params['DEL_N']]);
			
		if ($where) {
			$strWhere = self::checkWhere($where);
			$Orders->andWhere($strWhere[0], $strWhere[1]);
		}

		$Orders = $Orders->orderBy($order.' ASC')->all();

		if (empty($Orders)) {
				return RenderMessage::get(10002, '数据已到最低端，无需修改');
		} else {

			$modified = time();
			$i        = 0;

			foreach ($Orders AS $val) {
				$id      = $val['id'];
				$orderId = $val[$order];
    
				if ($orderId > 1){
					$count   = (new Query())->update($table, array($order => $orderId-1, 'modified' => $modified ), 'id = :eid AND is_del = :ais_del', [':eid' => $val['id'], ':ais_del' => Yii::$app->params['DEL_N']]);

					if ($count) $i ++ ;
				}
			}

			if ($i >0) {
				return RenderMessage::get(200, '删除成功');
			} else {
				return RenderMessage::get(10002, '删除失败');
			}
		}
	}

}
