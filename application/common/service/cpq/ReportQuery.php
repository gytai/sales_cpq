<?php

namespace app\common\service\cpq;

use think\db\Query;

/**
 * 报表查询包装。
 *
 * TP5 原生 Query 在 select()/find() 等终结操作后清空查询状态，而报表
 * 契约要求统一筛选后的查询仍可继续链式构建（例如 select 之后再 sum
 * 汇总同一结果集）。本类在终结操作后恢复状态快照，不改变任何 SQL 语义。
 */
class ReportQuery extends Query
{
    /**
     * 由既有 Query 复制连接与全部查询状态（表、别名、JOIN、WHERE、绑定）。
     *
     * @param Query $query
     * @return static
     */
    public static function fromQuery(Query $query)
    {
        $report = new static($query->getConnection());
        $options = $query->getOptions();
        if (empty($options['table'])) {
            $options['table'] = $query->getTable();
        }
        $report->options = $options;
        $report->bind = $query->getBind();
        return $report;
    }

    /**
     * 查询后恢复状态，允许在同一对象上继续聚合统计。
     *
     * @param mixed $data
     * @return mixed
     */
    public function select($data = null)
    {
        $options = $this->options;
        $bind = $this->bind;
        try {
            return parent::select($data);
        } finally {
            $this->options = $options;
            $this->bind = $bind;
        }
    }

    /**
     * 金额汇总保持 Decimal 字符串（原生实现强制转 float 会丢失精度），
     * 聚合后同样恢复状态，允许同一实例继续链式操作。
     *
     * @param string $field
     * @return string
     */
    public function sum($field)
    {
        $options = $this->options;
        $bind = $this->bind;
        try {
            $value = $this->aggregate('SUM', $field);
        } finally {
            $this->options = $options;
            $this->bind = $bind;
        }
        return $value === null ? '0' : (string)$value;
    }
}
