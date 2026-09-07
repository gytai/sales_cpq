<?php

namespace app\common\service\cpq;

use app\common\library\cpq\RuleAnalyzer;
use app\common\library\cpq\RuleDsl;
use app\common\repository\cpq\ConfigurationSchemaRepository;
use InvalidArgumentException;
use RuntimeException;
use think\Config;
use think\Db;
use think\Model;

/**
 * CPQ 产品与配置主数据生命周期服务（GYTAI-67，方案 §5.1 / §6.4 / §9.1）。
 *
 * 职责（跨表校验全部下沉到本服务，Controller 只做参数接收与响应）：
 *  - 发布校验：按表实施跨表业务校验（系列须有型号、型号须有可用配置组、
 *    规则 DSL 白名单、模板配置合法性、BOM 映射与型号结构一致）；
 *  - 版本约束：草稿/待审批/已发布/已失效状态机，已发布不可编辑、只有
 *    草稿可删除；发布新版本自动接替（supersede）同编码的旧已发布版本；
 *  - 复制新版本：已发布/已失效记录复制为下一版本草稿；
 *  - 引用保护：被引用的主数据不可物理删除（服务层友好提示 + 数据库
 *    外键兜底）；
 *  - 审计：所有状态迁移在同一事务内写入审计日志。
 */
class MasterDataLifecycleService
{
    /** 走 草稿→待审批→已发布 完整状态机的表 */
    const VERSIONED_TABLES = [
        'cpq_product_series',
        'cpq_product_model',
        'cpq_config_rule',
        'cpq_config_template',
        'cpq_bom_mapping',
        'cpq_price_book',
        'cpq_price_policy',
        'cpq_price_rule',
    ];

    /** 发布无需审批（草稿可直接发布）的表 */
    const NO_APPROVAL_TABLES = ['cpq_bom_mapping'];

    /** 型号子表：写入时父型号必须仍可编辑（草稿/待审批） */
    const MODEL_CHILD_TABLES = ['cpq_model_parameter', 'cpq_model_option_group'];

    /** @var AuditLogService */
    private $audit;

    public function __construct(AuditLogService $audit = null)
    {
        $this->audit = $audit ?: new AuditLogService();
    }

    // ------------------------------------------------------------------
    // 状态机判定
    // ------------------------------------------------------------------

    /**
     * 表是否为版本化生命周期实体。
     *
     * @param string $table 逻辑表名（不含前缀）
     * @return bool
     */
    public function isVersioned($table)
    {
        return in_array($table, self::VERSIONED_TABLES, true);
    }

    /**
     * 表是否需要提交审批后才可发布。
     *
     * @param string $table
     * @return bool
     */
    public function requiresApproval($table)
    {
        return $this->isVersioned($table) && !in_array($table, self::NO_APPROVAL_TABLES, true);
    }

    /**
     * 已发布/已失效版本不可直接编辑（变更须走"复制新版本"）。
     *
     * @param string $table
     * @param array  $row
     * @throws InvalidArgumentException
     */
    public function assertEditable($table, array $row)
    {
        if (!$this->isVersioned($table)) {
            return;
        }
        if (in_array($row['status'] ?? '', ['published', 'expired'], true)) {
            throw new InvalidArgumentException('已发布或已失效版本不可直接修改，请复制新版本');
        }
    }

    /**
     * 只有草稿版本可以删除。
     *
     * @param string $table
     * @param array  $row
     * @throws InvalidArgumentException
     */
    public function assertDraftDeletable($table, array $row)
    {
        if (!$this->isVersioned($table)) {
            return;
        }
        if (($row['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('只有草稿版本可以删除');
        }
    }

    // ------------------------------------------------------------------
    // 发布 / 失效 / 复制新版本
    // ------------------------------------------------------------------

    /**
     * 发布版本化记录：跨表校验 → 状态迁移 → 同编码旧版本接替 → 审计。
     *
     * @param Model                    $row
     * @param ProductLineScopeService|null $scope 传入时执行产品线数据范围校验
     * @throws InvalidArgumentException
     */
    public function publish(Model $row, ProductLineScopeService $scope = null)
    {
        $table = $this->logicalTable($row->getTable());
        if (!$this->isVersioned($table)) {
            throw new InvalidArgumentException('该类型不是版本化记录，不能发布');
        }
        $this->assertLineScope($table, $row->toArray(), $scope);

        $publishableStatus = $this->requiresApproval($table) ? 'pending' : 'draft';
        if (($row['status'] ?? '') !== $publishableStatus) {
            throw new InvalidArgumentException(
                $this->requiresApproval($table) ? '只有待审批版本可以发布' : '只有草稿版本可以发布'
            );
        }

        $this->validateForPublish($table, $row->toArray());

        Db::startTrans();
        try {
            $superseded = $this->supersedePreviousVersion($table, $row);
            $row->save(['status' => 'published']);
            $this->audit->record(
                AuditLogService::ACTION_PUBLISH,
                $table,
                $row[$row->getPk()],
                [
                    'code' => (string)($row['code'] ?? $row['material_code'] ?? ''),
                    'version' => (int)($row['version'] ?? 1),
                    'superseded_version' => $superseded,
                ],
                (string)($row['code'] ?? '')
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /**
     * 停用（失效）已发布版本。
     *
     * @param Model                        $row
     * @param ProductLineScopeService|null $scope
     * @throws InvalidArgumentException
     */
    public function expire(Model $row, ProductLineScopeService $scope = null)
    {
        $table = $this->logicalTable($row->getTable());
        if (!$this->isVersioned($table)) {
            throw new InvalidArgumentException('该类型不是版本化记录，不能停用');
        }
        $this->assertLineScope($table, $row->toArray(), $scope);
        if (($row['status'] ?? '') !== 'published') {
            throw new InvalidArgumentException('只有已发布版本可以停用');
        }

        Db::startTrans();
        try {
            $row->save(['status' => 'expired']);
            $this->audit->record(
                AuditLogService::ACTION_EXPIRE,
                $table,
                $row[$row->getPk()],
                ['code' => (string)($row['code'] ?? $row['material_code'] ?? ''), 'version' => (int)($row['version'] ?? 1)],
                (string)($row['code'] ?? '')
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /**
     * 复制新版本：已发布/已失效记录复制为下一版本草稿。
     *
     * 型号复制会连同其参数与配置结构一并复制；发布新版本时旧版本的
     * 子数据由 supersedePreviousVersion 接替处理。
     *
     * @param Model                        $row
     * @param ProductLineScopeService|null $scope
     * @return array 新版本记录
     * @throws InvalidArgumentException
     */
    public function copyNewVersion(Model $row, ProductLineScopeService $scope = null)
    {
        $table = $this->logicalTable($row->getTable());
        if (!$this->isVersioned($table)) {
            throw new InvalidArgumentException('该类型不是版本化记录，不能复制新版本');
        }
        $this->assertLineScope($table, $row->toArray(), $scope);
        if (!in_array($row['status'] ?? '', ['published', 'expired'], true)) {
            throw new InvalidArgumentException('只有已发布或已失效版本可以复制新版本，草稿可直接编辑');
        }

        // getData() 取原始字段，避免模型 $append 附加属性（如 status_text）进入 insert
        $data = $row->getData();
        $pk = $row->getPk();
        $code = (string)($data['code'] ?? '');

        if ($code !== '' && $table !== 'cpq_bom_mapping') {
            $openDraft = Db::name($table)
                ->where('code', $code)
                ->where('status', 'in', ['draft', 'pending'])
                ->count();
            if ($openDraft > 0) {
                throw new InvalidArgumentException('该编码已存在草稿或待审批版本，请先处理后再复制');
            }
        }

        $newVersion = $this->nextVersion($table, $data);
        unset($data[$pk], $data['createtime'], $data['updatetime']);
        $data['version'] = $newVersion;
        $data['status'] = 'draft';
        $now = time();
        $data['createtime'] = $now;
        $data['updatetime'] = $now;

        Db::startTrans();
        try {
            $newId = Db::name($table)->insertGetId($data);
            $copyDetail = [
                'source_id' => (int)$row[$pk],
                'source_version' => (int)($row['version'] ?? 1),
                'new_version' => $newVersion,
            ];
            if ($table === 'cpq_product_model') {
                $this->duplicateModelChildren((int)$row[$pk], $newId);
            } elseif ($table === 'cpq_price_book') {
                $copyDetail['copied_price_entries'] = $this->duplicatePriceBookEntries((int)$row[$pk], $newId);
            }
            $this->audit->record(
                AuditLogService::ACTION_COPY,
                $table,
                $newId,
                $copyDetail,
                $code
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }

        $data[$pk] = $newId;
        return $data;
    }

    // ------------------------------------------------------------------
    // 删除引用保护
    // ------------------------------------------------------------------

    /**
     * 断言记录可物理删除：被引用的主数据不可删除（友好提示，数据库
     * 外键为兜底）。版本化实体的"仅草稿可删"守卫由 assertDraftDeletable
     * 单独实施。
     *
     * @param string $table 逻辑表名
     * @param array  $rows  待删除行（至少含主键与业务编码）
     * @throws InvalidArgumentException
     */
    public function assertDeletable($table, array $rows)
    {
        if (!$rows) {
            return;
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $blockers = $this->collectReferenceBlockers($table, $rows, $ids);
        if ($blockers) {
            throw new InvalidArgumentException('以下记录被引用，不能删除：' . implode('；', $blockers));
        }
    }

    // ------------------------------------------------------------------
    // 型号子表写入守卫
    // ------------------------------------------------------------------

    /**
     * 型号参数/配置结构等子表写入前校验：父型号必须仍可编辑。
     * 价格条目写入前校验：所属价格表必须仍可维护（草稿/待审批）。
     *
     * @param string $table
     * @param array  $data 含 model_id 或 price_book_id 的行数据
     * @throws InvalidArgumentException
     */
    public function assertWritableParent($table, array $data)
    {
        if ($table === 'cpq_price_entry') {
            $priceBookId = (int)($data['price_book_id'] ?? 0);
            if ($priceBookId <= 0) {
                return;
            }
            $priceBook = Db::name('cpq_price_book')->where('id', $priceBookId)->field('id,code,status')->find();
            if (!$priceBook) {
                throw new InvalidArgumentException('所属价格表不存在');
            }
            if (!in_array($priceBook['status'], ['draft', 'pending'], true)) {
                throw new InvalidArgumentException('所属价格表已发布或已失效，请复制新版本后维护价格条目');
            }
            return;
        }
        if (!in_array($table, self::MODEL_CHILD_TABLES, true)) {
            return;
        }
        $modelId = (int)($data['model_id'] ?? 0);
        if ($modelId <= 0) {
            return;
        }
        $model = Db::name('cpq_product_model')->where('id', $modelId)->field('id,code,status')->find();
        if (!$model) {
            throw new InvalidArgumentException('所属产品型号不存在');
        }
        if (in_array($model['status'], ['published', 'expired'], true)) {
            throw new InvalidArgumentException('所属型号已发布或已失效，请复制新版本后修改其参数与配置结构');
        }
    }

    // ------------------------------------------------------------------
    // 产品线解析
    // ------------------------------------------------------------------

    /**
     * 解析一条记录归属的产品线；返回 null 表示全局字典（不受产品线范围约束）。
     *
     * @param string $table
     * @param array  $data
     * @return string|null
     */
    public function resolveProductLine($table, array $data)
    {
        switch ($table) {
            case 'cpq_product_series':
            case 'cpq_accessory_service':
                return (string)($data['product_line'] ?? '');
            case 'cpq_product_model':
                return $this->seriesLineOfModel($data);
            case 'cpq_model_parameter':
            case 'cpq_model_option_group':
            case 'cpq_config_template':
            case 'cpq_bom_mapping':
                return $this->seriesLineOfModelId((int)($data['model_id'] ?? 0));
            case 'cpq_config_rule':
                $line = trim((string)($data['product_line'] ?? ''));
                if ($line !== '') {
                    return $line;
                }
                return $this->seriesLineOfModelId((int)($data['model_id'] ?? 0));
            case 'cpq_price_policy':
                return (string)($data['product_line'] ?? '');
            case 'cpq_price_book':
            case 'cpq_price_rule':
                // 公司级全局数据，不受产品线范围约束
                return null;
            default:
                return null;
        }
    }

    /**
     * 对版本化/带产品线的记录执行数据范围断言。
     *
     * @param string                       $table
     * @param array                        $data
     * @param ProductLineScopeService|null $scope
     * @throws InvalidArgumentException
     */
    public function assertLineScope($table, array $data, ProductLineScopeService $scope = null)
    {
        if ($scope === null) {
            return;
        }
        $line = $this->resolveProductLine($table, $data);
        if ($line === null) {
            return;
        }
        $scope->assertLineAllowed($line);
    }

    // ------------------------------------------------------------------
    // 内部实现：发布校验
    // ------------------------------------------------------------------

    /**
     * 发布前跨表业务校验。
     *
     * @param string $table
     * @param array  $data
     * @throws InvalidArgumentException
     */
    private function validateForPublish($table, array $data)
    {
        switch ($table) {
            case 'cpq_product_series':
                $this->validateSeriesForPublish($data);
                break;
            case 'cpq_product_model':
                $this->validateModelForPublish($data);
                break;
            case 'cpq_config_rule':
                $this->validateRuleForPublish($data);
                break;
            case 'cpq_config_template':
                $this->validateTemplateForPublish($data);
                break;
            case 'cpq_bom_mapping':
                $this->validateBomMappingForPublish($data);
                break;
            case 'cpq_price_book':
                (new PricePolicyService())->assertBookScopeNoOverlap($data, (int)$data['id']);
                break;
            case 'cpq_price_policy':
                $policyService = new PricePolicyService();
                PricePolicyService::assertPriceHierarchy(
                    (string)($data['guide_price'] ?? '0'),
                    (string)($data['line_floor'] ?? '0'),
                    (string)($data['company_floor'] ?? '0')
                );
                $policyService->assertReferencedDimensionsExist($data);
                $policyService->assertNoDimensionOverlap($data, (int)$data['id']);
                break;
            case 'cpq_price_rule':
                (new PriceRuleService())->assertPublishable($data, (int)$data['id']);
                break;
        }
    }

    /**
     * 系列发布：至少包含一个有效（未失效）型号。
     */
    private function validateSeriesForPublish(array $data)
    {
        $count = Db::name('cpq_product_model')
            ->where('series_id', (int)$data['id'])
            ->where('status', '<>', 'expired')
            ->count();
        if ($count < 1) {
            throw new InvalidArgumentException('产品系列至少需要一个有效型号才能发布');
        }
    }

    /**
     * 型号发布：至少一个可见配置组；单选/多选组必须有可用选项。
     */
    private function validateModelForPublish(array $data)
    {
        $mappings = Db::name('cpq_model_option_group')
            ->alias('mapping')
            ->join('__CPQ_OPTION_GROUP__ option_group', 'option_group.id = mapping.group_id')
            ->where('mapping.model_id', (int)$data['id'])
            ->where('mapping.is_visible', 1)
            ->field('mapping.group_id,option_group.name,option_group.input_type')
            ->select();
        if (!$mappings) {
            throw new InvalidArgumentException('产品型号至少需要一个可见配置组才能发布');
        }
        foreach ($mappings as $mapping) {
            if (!in_array($mapping['input_type'], ['single', 'multiple'], true)) {
                continue;
            }
            $optionCount = Db::name('cpq_option_value')
                ->where('group_id', $mapping['group_id'])
                ->where('status', 'normal')
                ->count();
            if ($optionCount < 1) {
                throw new InvalidArgumentException($mapping['name'] . '没有可用选项，不能发布型号');
            }
        }
    }

    /**
     * 规则发布：DSL 白名单校验 + 适用范围 + 引用型号存在 +
     * 静态分析（循环依赖 / 永真冲突 / 不可达选项，方案 P18 / GYTAI-66）。
     */
    private function validateRuleForPublish(array $data)
    {
        RuleDsl::assertConditionJson($data['condition_json'] ?? '');
        RuleDsl::assertActionJson($data['action_json'] ?? '');
        RuleDsl::assertScope($data['model_id'] ?? 0, $data['product_line'] ?? '');

        $modelId = (int)($data['model_id'] ?? 0);
        if ($modelId > 0) {
            $exists = Db::name('cpq_product_model')->where('id', $modelId)->count();
            if (!$exists) {
                throw new InvalidArgumentException('适用型号不存在，不能发布规则');
            }
        }

        $this->assertRuleSetConsistent($data);
    }

    /**
     * 规则静态分析（GYTAI-66，方案 P18）：把候选规则放进其生效范围内的
     * 已发布规则全集，检查循环依赖、永真冲突和不可达选项。
     *
     * 范围与 ConfigurationSchemaRepository 读取口径一致：型号规则 =
     * 该型号规则 + 所属产品线的产线级规则；产线级规则之间单独成集。
     * 同编码旧发布版本在发布时会被接替，不参与分析。
     *
     * @param array $data 候选规则行
     * @throws InvalidArgumentException
     */
    private function assertRuleSetConsistent(array $data)
    {
        $set = $this->collectEffectiveRuleSet($data);
        RuleAnalyzer::assertPublishable($set['rules'], $set['groups']);
    }

    /**
     * 规则静态分析（供后台规则编辑器"冲突检测"使用）：与发布门禁同一套
     * 分析逻辑，但以结构化数组返回结果而不抛异常。
     *
     * @param array $data 候选规则行
     * @return array ['cycles'=>[...], 'conflicts'=>[...], 'unreachable'=>[...]]
     */
    public function analyzeRuleSet(array $data)
    {
        $set = $this->collectEffectiveRuleSet($data);
        return RuleAnalyzer::analyze($set['rules'], $set['groups']);
    }

    /**
     * 收集候选规则生效范围内的已发布规则全集与配置组骨架。
     *
     * @param array $data 候选规则行
     * @return array ['rules'=>[...], 'groups'=>[...]]
     */
    public function collectEffectiveRuleSet(array $data)
    {
        $modelId = (int)($data['model_id'] ?? 0);
        $productLine = trim((string)($data['product_line'] ?? ''));

        $query = Db::name('cpq_config_rule')
            ->where('status', 'published')
            ->where('code', '<>', (string)($data['code'] ?? ''));
        if ($modelId > 0) {
            $line = $productLine !== '' ? $productLine : $this->seriesLineOfModelId($modelId);
            $query->where(function ($scopeQuery) use ($modelId, $line) {
                $scopeQuery->where('model_id', $modelId);
                if ($line !== '') {
                    $scopeQuery->whereOr(function ($lineQuery) use ($line) {
                        $lineQuery->whereNull('model_id')->where('product_line', $line);
                    });
                }
            });
        } else {
            $query->whereNull('model_id')->where('product_line', $productLine);
        }

        $rules = [];
        foreach ($query->select() as $row) {
            $rules[] = [
                'code' => $row['code'],
                'severity' => $row['severity'],
                'condition' => RuleDsl::assertConditionJson($row['condition_json']),
                'actions' => RuleDsl::assertActionJson($row['action_json']),
            ];
        }
        $rules[] = [
            'code' => (string)$data['code'],
            'severity' => (string)($data['severity'] ?? 'blocking'),
            'condition' => RuleDsl::assertConditionJson($data['condition_json'] ?? ''),
            'actions' => RuleDsl::assertActionJson($data['action_json'] ?? ''),
        ];

        return [
            'rules' => $rules,
            'groups' => $modelId > 0 ? $this->loadGroupSkeleton($modelId) : [],
        ];
    }

    /**
     * 装载型号配置组骨架（编码 + 选项编码），供不可达选项检测使用。
     *
     * @param int $modelId
     * @return array
     */
    private function loadGroupSkeleton($modelId)
    {
        $mappings = Db::name('cpq_model_option_group')
            ->where('model_id', (int)$modelId)
            ->where('is_visible', 1)
            ->column('group_id');
        if (!$mappings) {
            return [];
        }
        $groups = Db::name('cpq_option_group')
            ->where('id', 'in', $mappings)
            ->where('status', 'normal')
            ->field('id,code,input_type')
            ->select();
        $options = Db::name('cpq_option_value')
            ->where('group_id', 'in', $mappings)
            ->where('status', 'normal')
            ->field('group_id,code')
            ->select();
        $optionsByGroupId = [];
        foreach ($options as $option) {
            $optionsByGroupId[(int)$option['group_id']][] = ['code' => $option['code']];
        }
        $skeleton = [];
        foreach ($groups as $group) {
            $skeleton[] = [
                'code' => $group['code'],
                'input_type' => $group['input_type'],
                'options' => $optionsByGroupId[(int)$group['id']] ?? [],
            ];
        }
        return $skeleton;
    }

    /**
     * 模板发布：配置必须通过当前有效规则全集校验（方案 P19）。
     */
    private function validateTemplateForPublish(array $data)
    {
        $configuration = json_decode((string)($data['config_json'] ?? ''), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($configuration)) {
            throw new InvalidArgumentException('产品配置必须是合法 JSON 对象');
        }
        try {
            $schema = (new ConfigurationSchemaRepository())->getPublishedSchema((int)$data['model_id']);
            $validation = (new ConfigurationService())->validate($schema, $configuration);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage());
        }
        if (!$validation['is_valid']) {
            $messages = array_column($validation['errors'], 'message');
            throw new InvalidArgumentException('模板配置不合法：' . implode('；', $messages));
        }
    }

    /**
     * BOM 映射发布：物料必填；选项存在且属于该型号配置结构。
     */
    private function validateBomMappingForPublish(array $data)
    {
        if (trim((string)($data['material_code'] ?? '')) === '') {
            throw new InvalidArgumentException('物料编码不能为空');
        }
        $optionValueId = $data['option_value_id'] ?? null;
        if ($optionValueId === null || (int)$optionValueId <= 0) {
            return;
        }
        $option = Db::name('cpq_option_value')
            ->where('id', (int)$optionValueId)
            ->field('id,group_id,code')
            ->find();
        if (!$option) {
            throw new InvalidArgumentException('映射的配置选项不存在');
        }
        $structureGroupIds = Db::name('cpq_model_option_group')
            ->where('model_id', (int)$data['model_id'])
            ->column('group_id');
        if (!in_array((int)$option['group_id'], array_map('intval', $structureGroupIds), true)) {
            throw new InvalidArgumentException('映射的选项不属于该型号的配置结构');
        }
    }

    // ------------------------------------------------------------------
    // 内部实现：版本接替
    // ------------------------------------------------------------------

    /**
     * 发布新版本时接替同编码旧已发布版本：
     *  - 系列：旧版本失效，其下型号改挂新版本系列行（同一逻辑系列）；
     *  - 型号：旧版本失效，规则/模板/BOM 引用改挂新行，旧版本子数据
     *    （参数/配置结构）在复制时已复制到新行，此处清理旧版本残留；
     *  - 规则/模板：同编码旧已发布版本失效；
     *  - BOM 映射：无接替（读取端按 (选项,物料) 取最高版本）。
     *
     * @param string $table
     * @param Model  $row
     * @return int|null 被接替的旧版本号
     */
    private function supersedePreviousVersion($table, Model $row)
    {
        $pk = $row->getPk();
        $newId = (int)$row[$pk];

        if ($table === 'cpq_bom_mapping') {
            return null;
        }

        $previous = Db::name($table)
            ->where('code', (string)$row['code'])
            ->where('id', '<>', $newId)
            ->where('status', 'published')
            ->order('version desc')
            ->find();
        if (!$previous) {
            return null;
        }

        Db::name($table)->where('id', $previous['id'])->update(['status' => 'expired', 'updatetime' => time()]);

        if ($table === 'cpq_product_series') {
            // 型号改挂新版本系列行，保证系列升级不影响在售型号
            Db::name('cpq_product_model')->where('series_id', $previous['id'])->update([
                'series_id' => $newId,
                'updatetime' => time(),
            ]);
        } elseif ($table === 'cpq_product_model') {
            // 规则/模板/BOM 引用改挂新行；旧版本子数据已被复制到新行，清理残留
            foreach (['cpq_config_rule', 'cpq_config_template', 'cpq_bom_mapping'] as $referenceTable) {
                Db::name($referenceTable)->where('model_id', $previous['id'])->update([
                    'model_id' => $newId,
                    'updatetime' => time(),
                ]);
            }
            Db::name('cpq_model_parameter')->where('model_id', $previous['id'])->delete();
            Db::name('cpq_model_option_group')->where('model_id', $previous['id'])->delete();
        }

        return (int)$previous['version'];
    }

    /**
     * 计算复制目标的下一版本号。
     *
     * @param string $table
     * @param array  $data
     * @return int
     */
    private function nextVersion($table, array $data)
    {
        $query = Db::name($table);
        if ($table === 'cpq_bom_mapping') {
            // BOM 映射无业务编码，按 (型号, 选项, 物料) 维度递增版本
            $query = $query->where('model_id', (int)$data['model_id'])
                ->where('material_code', (string)$data['material_code']);
            $optionValueId = $data['option_value_id'] ?? null;
            $query = $optionValueId === null || (int)$optionValueId <= 0
                ? $query->whereNull('option_value_id')
                : $query->where('option_value_id', (int)$optionValueId);
        } else {
            $query = $query->where('code', (string)$data['code']);
        }
        $maxVersion = (int)$query->max('version');
        return max($maxVersion + 1, (int)($data['version'] ?? 1) + 1);
    }

    /**
     * 复制型号时连同参数与配置结构一并复制。
     *
     * @param int $sourceModelId
     * @param int $targetModelId
     */
    private function duplicateModelChildren($sourceModelId, $targetModelId)
    {
        $now = time();
        $parameters = Db::name('cpq_model_parameter')->where('model_id', $sourceModelId)->select();
        foreach ($parameters as $parameter) {
            unset($parameter['id'], $parameter['createtime'], $parameter['updatetime']);
            $parameter['model_id'] = $targetModelId;
            $parameter['createtime'] = $now;
            $parameter['updatetime'] = $now;
            Db::name('cpq_model_parameter')->insert($parameter);
        }
        $structures = Db::name('cpq_model_option_group')->where('model_id', $sourceModelId)->select();
        foreach ($structures as $structure) {
            unset($structure['id'], $structure['createtime'], $structure['updatetime']);
            $structure['model_id'] = $targetModelId;
            $structure['createtime'] = $now;
            $structure['updatetime'] = $now;
            Db::name('cpq_model_option_group')->insert($structure);
        }
    }

    /**
     * 复制价格表时继承全部价格条目，后续只需在新草稿上维护差异。
     *
     * @param int $sourcePriceBookId
     * @param int $targetPriceBookId
     * @return int
     */
    private function duplicatePriceBookEntries($sourcePriceBookId, $targetPriceBookId)
    {
        $now = time();
        $entries = Db::name('cpq_price_entry')->where('price_book_id', $sourcePriceBookId)->select();
        foreach ($entries as &$entry) {
            unset($entry['id']);
            $entry['price_book_id'] = $targetPriceBookId;
            $entry['createtime'] = $now;
            $entry['updatetime'] = $now;
        }
        unset($entry);

        return empty($entries) ? 0 : (int)Db::name('cpq_price_entry')->insertAll($entries);
    }

    // ------------------------------------------------------------------
    // 内部实现：引用检查
    // ------------------------------------------------------------------

    /**
     * 收集删除阻断原因（每个受阻断的记录一条）。
     *
     * @param string $table
     * @param array  $rows
     * @param array  $ids
     * @return array
     */
    private function collectReferenceBlockers($table, array $rows, array $ids)
    {
        $blockers = [];
        $labelOf = function (array $row) {
            return (string)($row['code'] ?? $row['name'] ?? $row['material_code'] ?? ('#' . $row['id']));
        };

        $check = function ($referenceTable, $column, $label) use ($ids, &$blockers) {
            $referencing = Db::name($referenceTable)
                ->where($column, 'in', $ids)
                ->field($column . ' AS blocked_id,COUNT(*) AS total')
                ->group($column)
                ->select();
            foreach ($referencing as $hit) {
                $blockers[(int)$hit['blocked_id']] = [$label, (int)$hit['total']];
            }
        };

        switch ($table) {
            case 'cpq_product_series':
                $check('cpq_product_model', 'series_id', '产品型号');
                break;
            case 'cpq_product_model':
                $check('cpq_model_parameter', 'model_id', '型号参数');
                $check('cpq_model_option_group', 'model_id', '型号配置结构');
                $check('cpq_config_rule', 'model_id', '配置规则');
                $check('cpq_config_template', 'model_id', '配置模板');
                $check('cpq_bom_mapping', 'model_id', 'BOM映射');
                $this->collectPriceTargetBlockers('model', $ids, $blockers);
                break;
            case 'cpq_parameter_definition':
                $check('cpq_model_parameter', 'parameter_id', '型号参数');
                break;
            case 'cpq_option_group':
                $check('cpq_option_value', 'group_id', '配置选项');
                $check('cpq_model_option_group', 'group_id', '型号配置结构');
                break;
            case 'cpq_option_value':
                $check('cpq_bom_mapping', 'option_value_id', 'BOM映射');
                $this->collectDefaultValueBlockers($rows, $blockers);
                $this->collectPriceTargetBlockers('option', $ids, $blockers);
                break;
            case 'cpq_accessory_service':
                $this->collectPriceEntryBlockers($ids, $blockers);
                break;
            case 'cpq_customer_level':
                $check('cpq_customer', 'customer_level_id', '客户');
                break;
            case 'cpq_agent_level':
                $check('cpq_agent', 'agent_level_id', '代理商');
                break;
            case 'cpq_region':
                $check('cpq_customer', 'region_id', '客户');
                $this->collectRegionCodeBlockers($rows, $blockers);
                break;
            case 'cpq_sales_org':
                $check('cpq_customer', 'sales_org_id', '客户');
                $check('cpq_region', 'sales_org_id', '销售区域');
                break;
            case 'cpq_customer':
                $check('cpq_agent', 'customer_id', '代理商');
                $check('cpq_customer', 'agent_id', '终端客户');
                $check('cpq_price_policy', 'customer_id', '价格策略');
                break;
            case 'cpq_agent':
                $check('cpq_customer', 'agent_id', '终端客户');
                break;
        }

        // 用业务编码 + 引用统计生成可读的阻断信息
        $messages = [];
        foreach ($blockers as $id => $info) {
            foreach ($rows as $row) {
                if ((int)$row['id'] === $id) {
                    $messages[] = sprintf('%s 被 %s 引用（%d 条）', $labelOf($row), $info[0], $info[1]);
                    break;
                }
            }
        }
        return $messages;
    }

    /**
     * 选项被型号配置结构的默认值 JSON 引用时不可删除。
     *
     * @param array $rows
     * @param array $blockers
     */
    private function collectDefaultValueBlockers(array $rows, array &$blockers)
    {
        foreach ($rows as $row) {
            $like = '%"' . $row['code'] . '"%';
            $count = Db::name('cpq_model_option_group')
                ->where('group_id', (int)$row['group_id'])
                ->where('default_value', 'like', $like)
                ->count();
            if ($count > 0) {
                $blockers[(int)$row['id']] = ['型号默认配置', (int)$count];
            }
        }
    }

    /**
     * 配件/服务被价格条目引用时不可删除（价格条目 M2 启用，此处前置保护）。
     *
     * @param array $ids
     * @param array $blockers
     */
    private function collectPriceEntryBlockers(array $ids, array &$blockers)
    {
        $referencing = Db::name('cpq_price_entry')
            ->where('target_type', 'accessory_service')
            ->where('target_id', 'in', $ids)
            ->field('target_id AS blocked_id,COUNT(*) AS total')
            ->group('target_id')
            ->select();
        foreach ($referencing as $hit) {
            $blockers[(int)$hit['blocked_id']] = ['价格条目', (int)$hit['total']];
        }
    }

    /**
     * 定价对象（型号/选项）被价格条目或价格策略按 target_type 引用时
     * 不可删除。
     *
     * @param string $targetType model / option / accessory_service
     * @param array  $ids
     * @param array  $blockers
     */
    private function collectPriceTargetBlockers($targetType, array $ids, array &$blockers)
    {
        foreach ([['cpq_price_entry', '价格条目'], ['cpq_price_policy', '价格策略']] as $pair) {
            $referencing = Db::name($pair[0])
                ->where('target_type', $targetType)
                ->where('target_id', 'in', $ids)
                ->field('target_id AS blocked_id,COUNT(*) AS total')
                ->group('target_id')
                ->select();
            foreach ($referencing as $hit) {
                $this->mergeBlocker($blockers, (int)$hit['blocked_id'], $pair[1], (int)$hit['total']);
            }
        }
    }

    /**
     * 销售区域按编码（region_code）被价格策略/税率规则引用时不可删除。
     * （编码匹配而非 id 匹配，参考 collectPriceEntryBlockers 的写法）
     *
     * @param array $rows cpq_region 行（至少含 id、code）
     * @param array $blockers
     */
    private function collectRegionCodeBlockers(array $rows, array &$blockers)
    {
        $idOfCode = [];
        foreach ($rows as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code !== '') {
                $idOfCode[$code] = (int)$row['id'];
            }
        }
        if (!$idOfCode) {
            return;
        }
        foreach ([['cpq_price_policy', '价格策略'], ['cpq_tax_rule', '税率规则']] as $pair) {
            $referencing = Db::name($pair[0])
                ->where('region_code', 'in', array_keys($idOfCode))
                ->field('region_code AS blocked_code,COUNT(*) AS total')
                ->group('region_code')
                ->select();
            foreach ($referencing as $hit) {
                $blockedId = $idOfCode[$hit['blocked_code']] ?? 0;
                if ($blockedId > 0) {
                    $this->mergeBlocker($blockers, $blockedId, $pair[1], (int)$hit['total']);
                }
            }
        }
    }

    /**
     * 合并阻断信息：同一记录被多类数据引用时拼接标签并累加条数。
     *
     * @param array  $blockers
     * @param int    $id
     * @param string $label
     * @param int    $total
     */
    private function mergeBlocker(array &$blockers, $id, $label, $total)
    {
        if (isset($blockers[$id])) {
            $blockers[$id] = [$blockers[$id][0] . '、' . $label, $blockers[$id][1] + $total];
        } else {
            $blockers[$id] = [$label, $total];
        }
    }

    // ------------------------------------------------------------------
    // 工具方法
    // ------------------------------------------------------------------

    /**
     * 型号行数据 → 所属系列产品线。
     *
     * @param array $modelData
     * @return string
     */
    private function seriesLineOfModel(array $modelData)
    {
        return $this->seriesLineOfModelId((int)($modelData['id'] ?? 0), (int)($modelData['series_id'] ?? 0));
    }

    /**
     * 型号ID → 所属系列产品线。
     *
     * @param int $modelId
     * @param int $seriesId 已知系列ID时可跳过型号查询
     * @return string
     */
    private function seriesLineOfModelId($modelId, $seriesId = 0)
    {
        if ($seriesId <= 0 && $modelId > 0) {
            $seriesId = (int)Db::name('cpq_product_model')->where('id', $modelId)->value('series_id');
        }
        if ($seriesId <= 0) {
            return '';
        }
        return (string)Db::name('cpq_product_series')->where('id', $seriesId)->value('product_line');
    }

    /**
     * 物理表名 → 逻辑表名（去掉数据库前缀）。
     *
     * @param string $table
     * @return string
     */
    private function logicalTable($table)
    {
        $prefix = (string)Config::get('database.prefix');
        return $prefix !== '' && strpos($table, $prefix) === 0 ? substr($table, strlen($prefix)) : $table;
    }
}
