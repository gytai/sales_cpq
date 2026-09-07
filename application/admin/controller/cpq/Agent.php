<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\Agent as AgentModel;
use app\common\controller\Backend;

/**
 * 代理商
 *
 * @icon fa fa-handshake-o
 */
class Agent extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code';
    protected $multiFields = 'status';
    protected $cpqScopeType = null;
    protected $relationSearch = true;
    protected $cpqRelations = ['customer', 'agentLevel'];
    protected $cpqFormDefaults = [
        'code' => '', 'customer_id' => '', 'agent_level_id' => '', 'authorized_regions' => '',
        'authorized_lines' => '', 'auth_start_date' => '', 'auth_end_date' => '',
        'credit_limit' => '0', 'owner_id' => 0, 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new AgentModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
