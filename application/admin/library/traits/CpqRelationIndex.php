<?php

namespace app\admin\library\traits;

trait CpqRelationIndex
{
    public function add()
    {
        if (!$this->request->isPost()) {
            $this->view->assign('row', $this->cpqFormDefaults ?? []);
        }
        return parent::add();
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if (!$this->request->isAjax()) {
            return $this->view->fetch();
        }
        if ($this->request->request('keyField')) {
            return $this->selectpage();
        }

        list($where, $sort, $order, $offset, $limit) = $this->buildparams();
        $query = $this->model;
        if (!empty($this->cpqRelations)) {
            $this->relationSearch = true;
            $query = $query->with($this->cpqRelations);
        }
        $list = $query->where($where)->order($sort, $order)->paginate($limit);

        return json([
            'total' => $list->total(),
            'rows' => $list->items(),
        ]);
    }
}
