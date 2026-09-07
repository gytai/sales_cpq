<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\SalesOrgMember as SalesOrgMemberModel;
use app\common\controller\Backend;

/**
 * 销售组织成员
 *
 * @icon fa fa-user-plus
 */
class SalesOrgMember extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'org_id,admin_id,role';
    protected $multiFields = 'status';
    protected $cpqScopeType = null;
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'org_id' => '', 'admin_id' => '', 'role' => 'sales',
        'effective_date' => '', 'expiry_date' => '', 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new SalesOrgMemberModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
