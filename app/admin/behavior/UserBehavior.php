<?php

namespace app\admin\behavior;

use app\facade\UserInfo;
use think\Db;
use think\facade\{
    Log, Request
};

/**
 * Description of UserLogin
 * 使用方法
 * 用户登录行为记录
 * @author static7
 * //记录行为
 * $param=[
 *       'action'=>'user_login',
 *       'model'=>'member',
 *       'record_id'=>0,
 *       'user_id'=>0
 * ];
 * Hook::listen('user_behavior',$param);
 */
class UserBehavior
{
    /**
     * 日志行为记录
     * @param array $params 数组参数
     * @author staitc7 <static7@qq.com>
     */

    public function run($params)
    {
        $this->actionLog(
        $params['action'] ?: null,
        $params['model'] ?: null,
        $params['record_id'] ?: null,
        $params['user_id'] ?: 0,
        $params['type'] ?? 0
        );
    }

    /**
     * 记录行为日志，并执行该行为的规则
     * @param string $action 行为标识
     * @param string $model 触发行为的模型名
     * @param int    $record_id 触发行为的记录id
     * @param int    $userId 执行行为的用户id
     * @param int    $type 更新还是添加
     * @return bool
     * @author huajie <banhuajie@163.com>
     */
    public function actionLog($action = null, $model = null, $record_id = null, $userId = null,$type=0)
    {
        if (empty($action) || empty($model) || empty($record_id)) {//参数检查
            Log::record('[ UserBehavior ]： 参数丢失','debug');
            return false;
        }
        $user_id = $userId ?: UserInfo::userId();
        //查询行为,判断是否执行
        $field       = "id,log,rule,status";
        $action_info = Db::name('Action')->where('name', $action)->field($field)->find();
        if ($action_info['status'] != 1) {
            Log::record('[ UserBehavior ]： 该行为被禁用或删除','debug');
            return false;
        }
        //插入行为日志
        $data = [
            'type' => $type,
            'model' => $model,
            'user_id' => $user_id,
            'record_id' => $record_id,
            'create_time' => Request::time(),
            'action_id' => $action_info['id'],
            'action_ip' => Request::ip(1),
        ];

        //解析日志规则,生成日志备注
        if (empty($action_info['log'])) {
            //未定义日志规则，记录操作url
            $data['remark'] = '操作url：' . Request::url();
        } else {
            if (preg_match_all('/\[(\S+?)\]/', $action_info['log'], $match)) {
                $log = [
                    'user' => $user_id, 'record' => $record_id, 'model' => $model, 'time' => Request::time(),
                    'data' => [
                        'user' => $user_id, 'model' => $model, 'record' => $record_id, 'time' => Request::time()
                    ]
                ];
                foreach ($match[1] as $value) {
                    $param = explode('|', $value);
                    if (isset($param[1])) {
                        $replace[] = call_user_func($param[1], $log[ $param[0] ]);
                    } else {
                        $replace[] = $log[ $param[0] ];
                    }
                }
                $data['remark'] = str_replace($match[0], $replace, $action_info['log']);
            } else {
                $data['remark'] = $action_info['log'];
            }
        }
        Db::name('ActionLog')->insert($data);
        if (!empty($action_info['rule'])) {
            $rules = $this->parseAction($user_id, $action); //解析行为
            $this->executeAction($rules, $action_info['id'], $user_id); //执行行为
        }
    }

    /**
     * 解析行为规则
     * 规则定义  table:$table|field:$field|condition:$condition|rule:$rule[|cycle:$cycle|max:$max][;......]
     * 规则字段解释：table->要操作的数据表，不需要加表前缀；
     *              field->要操作的字段；
     *              condition->操作的条件，目前支持字符串，默认变量{$self}为执行行为的用户
     *              rule->对字段进行的具体操作，目前支持四则混合运算，如：1+score*2/2-3
     *              cycle->执行周期，单位（小时），表示$cycle小时内最多执行$max次
     *              max->单个周期内的最大执行次数（$cycle和$max必须同时定义，否则无效）
     * 单个行为后可加 ； 连接其他规则
     * @param int    $self 替换规则里的变量为执行用户的id
     * @param string $action 行为id或者name
     * @return boolean|array: false解析出错 ， 成功返回规则数组
     * @author huajie <banhuajie@163.com>
     */
    public function parseAction($self, $action = null)
    {
        if (empty($action)) {
            Log::record('[ UserBehavior ]： 该行为被禁用或删除','debug');
            return false;
        }
        //查询行为信息,参数支持id或者name
        $info  = Db::name('Action')->where(is_numeric($action) ? 'id' :'name',$action)->field("id,log,rule,status")->find();
        if (!$info || $info['status'] != 1) {
            Log::record('[ UserBehavior ]： 该行为被禁用或删除','debug');
            return false;
        }
        //解析规则:table:$table|field:$field|condition:$condition|rule:$rule[|cycle:$cycle|max:$max][;......]
        $rules  = $info['rule'];
        $rules  = str_replace('{$self}', $self, $rules);
        $rules  = array_filter(explode(';', $rules));
        $return = [];
        foreach ($rules as $key => &$rule) {
            $rule = explode('|', $rule);
            foreach ($rule as $k => $fields) {
                $field = empty($fields) ? [] : explode(':', $fields);
                if (!empty($field)) {
                    $return[ $key ][ $field[0] ] = $field[1];
                }
            }
            //cycle(检查周期)和max(周期内最大执行次数)必须同时存在，否则去掉这两个条件
            if (!array_key_exists('cycle', $return[ (int)$key ]) || !array_key_exists('max', $return[ (int)$key ])) {
                unset($return[ $key ]['cycle'], $return[ $key ]['max']);
            }
        }
        return $return;
    }

    /**
     * 执行行为
     * @param array|bool $rules 解析后的规则数组
     * @param int        $action_id 行为id
     * @param array      $user_id 执行的用户id
     * @return bool false 失败 ， true 成功
     * @author huajie <banhuajie@163.com>
     */
    public function executeAction($rules = false, $action_id = null, $user_id = null)
    {
        if (!$rules || empty($action_id) || empty($user_id)) {
            Log::record('[ UserBehavior ]： 参数丢失','debug');
            return false;
        }
        $return = true;
        foreach ($rules as $rule) {
            //检查执行周期
            $exec_count = Db::name('ActionLog')->where([
                ['action_id' ,'=', $action_id],
                ['user_id' ,'=', $user_id],
                ['create_time' ,'>', Request::time() - (int)$rule['cycle'] * 3600]
            ])->count();
            if ($exec_count > $rule['max']) {
                continue;
            }
            //执行数据库操作
            $res    = Db::name(ucfirst($rule['table']))->where($rule['condition'])->setField($rule['field'], [
                'exp', $rule['rule']
            ]);
            $return = $res ?: false;
        }
        return $return;
    }

}
