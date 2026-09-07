<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use think\Db;

/**
 * CPQ 报价模板服务（M3，P59）。
 *
 * - 变量白名单：模板结构中只允许引用白名单变量，未知变量在保存/预览时报错；
 * - 预览：以测试数据渲染模板并报告缺失变量；
 * - 市场默认：同一语言+市场仅一个默认模板，仅已发布模板可设默认；
 * - 发布：已发布模板内容不可直接修改（只能停用后复制新版本），与主数据版本约定一致。
 */
class QuoteTemplateService
{
    /**
     * 变量白名单：变量名 => 中文说明。
     * 渲染数据全部来自报价冻结快照（服务端组装），模板只做展示引用。
     */
    const VARIABLE_WHITELIST = [
        'quote.code' => '报价编码',
        'quote.name' => '报价名称',
        'quote.customer_name' => '客户名称',
        'quote.agent_name' => '代理商',
        'quote.sales_org_name' => '销售组织',
        'quote.currency' => '币种',
        'quote.date' => '报价日期',
        'quote.valid_until' => '有效期至',
        'quote.owner_name' => '销售负责人',
        'quote.untaxed_amount' => '未税金额',
        'quote.tax_amount' => '税额',
        'quote.total_amount' => '含税总额',
        'quote.line_count' => '明细行数',
        'company.name' => '公司名称',
        'company.name_en' => '公司英文名称',
        'company.address' => '公司地址',
        'company.contact' => '联系方式',
    ];

    /** 模板结构默认板块（保存时校验只允许这些 key） */
    const SECTION_KEYS = [
        'show_cover', 'show_company_info', 'show_product_table', 'show_technical_params',
        'show_terms', 'show_signature', 'show_watermark', 'header_text', 'footer_text',
        'watermark_text', 'cover_title', 'cover_subtitle', 'signature_note', 'remark',
    ];

    /** @var array|null 缓存默认结构 */
    private static $defaultContent = null;

    /**
     * 校验模板结构：板块 key 白名单 + 变量白名单（未知变量报错）。
     *
     * @param array $content
     * @return array 清理后的结构
     */
    public function validateContent(array $content)
    {
        $clean = [];
        foreach ($content as $key => $value) {
            if (!in_array($key, self::SECTION_KEYS, true)) {
                throw new InvalidArgumentException('模板结构包含未知板块：' . $key);
            }
            if (strpos($key, 'show_') === 0) {
                $clean[$key] = $value ? 1 : 0;
            } else {
                $clean[$key] = mb_substr(trim((string)$value), 0, 500);
            }
        }
        $unknown = $this->unknownVariables($clean);
        if ($unknown) {
            throw new InvalidArgumentException('模板引用了白名单之外的变量：' . implode('、', $unknown));
        }
        return $clean;
    }

    /**
     * 扫描结构文本中的 {{变量}} 引用，返回不在白名单内的变量。
     */
    public function unknownVariables(array $content)
    {
        $used = $this->usedVariables($content);
        $unknown = [];
        foreach ($used as $variable) {
            if (!isset(self::VARIABLE_WHITELIST[$variable])) {
                $unknown[] = $variable;
            }
        }
        return $unknown;
    }

    /**
     * 扫描结构文本中引用的全部变量。
     */
    public function usedVariables(array $content)
    {
        $variables = [];
        foreach ($content as $value) {
            if (!is_string($value)) {
                continue;
            }
            if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $value, $matches)) {
                foreach ($matches[1] as $variable) {
                    $variables[$variable] = true;
                }
            }
        }
        return array_keys($variables);
    }

    /**
     * 预览：以测试数据渲染模板 HTML，返回 [html, missing_variables]。
     * missing = 模板引用了但测试数据未提供的变量（正常报价数据中缺失的变量将在预览中标出）。
     */
    public function preview(array $template)
    {
        $content = $this->decodeContent($template);
        $testVars = $this->testVariables();
        $missing = [];
        foreach ($this->usedVariables($content) as $variable) {
            if (!isset(self::VARIABLE_WHITELIST[$variable])) {
                continue; // 白名单外变量在保存时已拦截
            }
            if (!array_key_exists($variable, $testVars)) {
                $missing[] = $variable;
            }
        }
        $lines = $this->testLines((string)$template['language']);
        return [
            'html' => $this->renderHtml($template, $testVars, $lines, (string)$template['language']),
            'missing_variables' => $missing,
        ];
    }

    /**
     * 用报价冻结快照渲染模板 HTML（PDF/页面预览共用）。
     *
     * @param array $template 模板行
     * @param array $vars     白名单变量值（由 QuoteDocumentService 组装）
     * @param array $lines    明细行（model_code/model_name/quantity/unit_price/amount）
     * @param string $language
     * @return string HTML
     */
    public function renderHtml(array $template, array $vars, array $lines, $language)
    {
        $content = $this->decodeContent($template);
        $isEn = $language === 'en';
        // 中文 PDF 必须显式使用 createMpdf() 注册的字体；sans-serif 会被 mPDF 映射为 DejaVu，缺少中文字形。
        $bodyFontFamily = $isEn ? 'sans-serif' : 'wqyzh, sans-serif';
        $label = function ($zh, $en) use ($isEn) {
            return $isEn ? $en : $zh;
        };

        $replace = [];
        foreach (self::VARIABLE_WHITELIST as $variable => $description) {
            $replace['{{' . $variable . '}}'] = isset($vars[$variable]) ? htmlspecialchars((string)$vars[$variable], ENT_QUOTES, 'UTF-8') : '';
            $replace['{{ ' . $variable . ' }}'] = $replace['{{' . $variable . '}}'];
        }
        $fill = function ($text) use ($replace) {
            return strtr((string)$text, $replace);
        };

        $html = '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:' . $bodyFontFamily . ';font-size:12px;color:#222;margin:24px}'
            . 'h1{font-size:22px;margin:0 0 4px}h2{font-size:15px;border-bottom:2px solid #2a6496;padding-bottom:4px;margin:18px 0 8px;color:#2a6496}'
            . 'table{width:100%;border-collapse:collapse;margin:6px 0}th,td{border:1px solid #bbb;padding:5px 7px;text-align:left}'
            . 'th{background:#eef3f8}.total{font-size:15px;font-weight:bold}.muted{color:#777}'
            . '.cover{text-align:center;margin:60px 0}.cover h1{font-size:28px}.cover .sub{font-size:14px;color:#666;margin-top:8px}'
            . '.sign{margin-top:40px;width:100%}.sign td{border:none;padding:12px 0;vertical-align:top}'
            . '.watermark{position:fixed;top:45%;left:20%;font-size:70px;color:rgba(180,180,180,0.25);transform:rotate(-25deg);z-index:-1}'
            . '</style></head><body>';

        if (!empty($content['show_watermark']) && trim((string)$content['watermark_text']) !== '') {
            $html .= '<div class="watermark">' . htmlspecialchars((string)$content['watermark_text'], ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if (!empty($content['header_text'])) {
            $html .= '<div style="border-bottom:1px solid #999;padding-bottom:6px;margin-bottom:12px">' . $fill($content['header_text']) . '</div>';
        }

        if (!empty($content['show_cover'])) {
            $html .= '<div class="cover"><h1>' . $fill($content['cover_title'] ?: ($isEn ? 'Quotation {{quote.code}}' : '报价单 {{quote.code}}')) . '</h1>'
                . '<div class="sub">' . $fill($content['cover_subtitle'] ?: ($isEn
                    ? 'Date: {{quote.date}}  Currency: {{quote.currency}}'
                    : '报价日期：{{quote.date}}　币种：{{quote.currency}}')) . '</div></div>';
        }

        if (!empty($content['show_company_info'])) {
            $html .= '<h2>' . $label('公司信息', 'Company') . '</h2>'
                . '<table><tr><th>' . $label('公司名称', 'Company') . '</th><td>' . $fill('{{company.name}}') . '</td>'
                . '<th>' . $label('地址', 'Address') . '</th><td>' . $fill('{{company.address}}') . '</td></tr>'
                . '<tr><th>' . $label('联系方式', 'Contact') . '</th><td>' . $fill('{{company.contact}}') . '</td>'
                . '<th>' . $label('销售负责人', 'Sales') . '</th><td>' . $fill('{{quote.owner_name}}') . '</td></tr></table>';
        }

        $html .= '<h2>' . $label('报价摘要', 'Quotation Summary') . '</h2>'
            . '<table><tr><th>' . $label('客户', 'Customer') . '</th><td>' . $fill('{{quote.customer_name}}') . '</td>'
            . '<th>' . $label('报价名称', 'Name') . '</th><td>' . $fill('{{quote.name}}') . '</td></tr>'
            . '<tr><th>' . $label('有效期至', 'Valid Until') . '</th><td>' . $fill('{{quote.valid_until}}') . '</td>'
            . '<th>' . $label('明细行数', 'Lines') . '</th><td>' . $fill('{{quote.line_count}}') . '</td></tr></table>';

        if (!empty($content['show_product_table'])) {
            $html .= '<h2>' . $label('产品明细', 'Products') . '</h2><table>'
                . '<tr><th>#</th><th>' . $label('型号编码', 'Model') . '</th><th>' . $label('名称', 'Description') . '</th>'
                . '<th>' . $label('数量', 'Qty') . '</th><th>' . $label('单价', 'Unit Price') . '</th>'
                . '<th>' . $label('金额', 'Amount') . '</th></tr>';
            foreach ($lines as $index => $line) {
                $html .= '<tr><td>' . ((int)($line['line_no'] ?? $index + 1)) . '</td>'
                    . '<td>' . htmlspecialchars((string)$line['model_code'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string)$line['model_name'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string)$line['quantity'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string)$line['unit_subtotal'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string)$line['total_amount'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            $html .= '<tr><th colspan="5" style="text-align:right">' . $label('未税金额', 'Untaxed') . '</th>'
                . '<td>' . $fill('{{quote.untaxed_amount}}') . '</td></tr>'
                . '<tr><th colspan="5" style="text-align:right">' . $label('税额', 'Tax') . '</th>'
                . '<td>' . $fill('{{quote.tax_amount}}') . '</td></tr>'
                . '<tr><th colspan="5" style="text-align:right">' . $label('含税总额', 'Total') . '</th>'
                . '<td class="total">' . $fill('{{quote.total_amount}}') . '</td></tr></table>';
        }

        if (!empty($content['show_technical_params']) && !empty($vars['__params_html'])) {
            $html .= '<h2>' . $label('技术参数', 'Technical Parameters') . '</h2><table>' . $vars['__params_html'] . '</table>';
        }

        if (!empty($content['show_terms'])) {
            $html .= '<h2>' . $label('商务条款', 'Terms & Conditions') . '</h2>' . (isset($vars['__terms_html']) ? $vars['__terms_html'] : '<p class="muted">（无条款）</p>');
        }

        if (!empty($content['show_signature'])) {
            $html .= '<div class="sign"><table width="100%"><tr>'
                . '<td width="50%"><b>' . $label('客户签章', 'Customer Signature') . '</b><div class="muted">' . $label('（签字/盖章）', '(Sign & Stamp)') . '</div></td>'
                . '<td width="50%"><b>' . $label('卖方签章', 'Seller Signature') . '</b><div class="muted">' . $fill($content['signature_note'] ?: ($isEn ? '(Sign & Stamp)' : '（签字/盖章）')) . '</div></td>'
                . '</tr></table></div>';
        }

        if (!empty($content['remark'])) {
            $html .= '<h2>' . $label('备注', 'Remark') . '</h2><p>' . $fill($content['remark']) . '</p>';
        }

        if (!empty($content['footer_text'])) {
            $html .= '<div style="border-top:1px solid #999;margin-top:18px;padding-top:6px" class="muted">' . $fill($content['footer_text']) . '</div>';
        }
        $html .= '</body></html>';
        return $html;
    }

    /**
     * 市场默认模板：语言+市场精确匹配 → all 兜底；仅已发布模板。
     */
    public function resolveDefaultTemplate($language, $marketScope)
    {
        $rows = Db::name('cpq_quote_template')
            ->where('status', 'published')
            ->where('language', (string)$language)
            ->where('is_default', 1)
            ->select();
        foreach ($rows as $row) {
            if ((string)$row['market_scope'] === (string)$marketScope) {
                return $row;
            }
        }
        foreach ($rows as $row) {
            if ((string)$row['market_scope'] === 'all') {
                return $row;
            }
        }
        return null;
    }

    /**
     * 设为市场默认：同语言+市场互斥；仅已发布模板可设默认。
     */
    public function setDefault($templateId)
    {
        $template = Db::name('cpq_quote_template')->where('id', (int)$templateId)->find();
        if (!$template) {
            throw new InvalidArgumentException('模板不存在');
        }
        if ($template['status'] !== 'published') {
            throw new InvalidArgumentException('仅已发布模板可设为市场默认');
        }
        Db::startTrans();
        try {
            Db::name('cpq_quote_template')
                ->where('language', $template['language'])
                ->where('market_scope', $template['market_scope'])
                ->where('id', '<>', (int)$templateId)
                ->update(['is_default' => 0, 'updatetime' => time()]);
            Db::name('cpq_quote_template')->where('id', (int)$templateId)->update([
                'is_default' => 1,
                'updatetime' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return true;
    }

    /**
     * 发布模板：内容校验通过后置为已发布；已发布模板不可直接改内容（复制新版本）。
     */
    public function publish($templateId)
    {
        $template = Db::name('cpq_quote_template')->where('id', (int)$templateId)->find();
        if (!$template) {
            throw new InvalidArgumentException('模板不存在');
        }
        if ($template['status'] === 'published') {
            throw new InvalidArgumentException('模板已发布，修改请复制新版本');
        }
        $this->validateContent($this->decodeContent($template));
        Db::name('cpq_quote_template')->where('id', (int)$templateId)->update([
            'status' => 'published',
            'updatetime' => time(),
        ]);
        return true;
    }

    public function decodeContent(array $template)
    {
        $content = json_decode((string)($template['content_json'] ?? ''), true);
        return is_array($content) ? $content : $this->defaultContent();
    }

    /**
     * 默认模板结构。
     */
    public function defaultContent($language = 'zh')
    {
        $isEn = $language === 'en';
        return [
            'show_cover' => 1,
            'show_company_info' => 1,
            'show_product_table' => 1,
            'show_technical_params' => 0,
            'show_terms' => 1,
            'show_signature' => 1,
            'show_watermark' => 0,
            'cover_title' => $isEn ? 'Quotation {{quote.code}}' : '报价单 {{quote.code}}',
            'cover_subtitle' => $isEn ? 'Date: {{quote.date}}  Currency: {{quote.currency}}' : '报价日期：{{quote.date}}　币种：{{quote.currency}}',
            'header_text' => $isEn ? '{{company.name_en}}' : '{{company.name}}',
            'footer_text' => $isEn
                ? 'This quotation is generated by CPQ system. File hash verified on download.'
                : '本报价单由 CPQ 系统生成，下载时校验文件哈希。',
            'watermark_text' => $isEn ? 'QUOTATION' : '报价单',
            'signature_note' => '',
            'remark' => '',
        ];
    }

    private function testVariables()
    {
        return [
            'quote.code' => 'Q-DEMO-0001',
            'quote.name' => 'CPQ-DEMO 演示报价',
            'quote.customer_name' => 'CPQ-DEMO-CUSTOMER-A',
            'quote.agent_name' => 'CPQ-DEMO-AGENT-01',
            'quote.sales_org_name' => 'CPQ-DEMO 销售组织',
            'quote.currency' => 'CNY',
            'quote.date' => date('Y-m-d'),
            'quote.valid_until' => date('Y-m-d', strtotime('+30 days')),
            'quote.owner_name' => '演示销售',
            'quote.untaxed_amount' => '120000.0000',
            'quote.tax_amount' => '15600.0000',
            'quote.total_amount' => '135600.0000',
            'quote.line_count' => '2',
            'company.name' => 'CPQ 演示制造有限公司',
            'company.name_en' => 'CPQ Demo Manufacturing Co., Ltd.',
            'company.address' => '演示省演示市演示路 1 号',
            'company.contact' => '+86 000 0000 0000',
        ];
    }

    private function testLines($language)
    {
        $isEn = $language === 'en';
        return [
            [
                'line_no' => 1,
                'model_code' => 'CPQ-DEMO-EQUIPMENT-A',
                'model_name' => $isEn ? 'Configurable Equipment A' : '通用可配置设备A',
                'quantity' => '2.0000',
                'unit_subtotal' => '50000.0000',
                'total_amount' => '100000.0000',
            ],
            [
                'line_no' => 2,
                'model_code' => 'CPQ-DEMO-MODULE-A',
                'model_name' => $isEn ? 'Function Module A' : '功能模块A',
                'quantity' => '1.0000',
                'unit_subtotal' => '20000.0000',
                'total_amount' => '20000.0000',
            ],
        ];
    }
}
