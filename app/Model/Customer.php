<?php

declare(strict_types=1);

namespace App\Model;

/**
 * ============================================================================
 * 客户模型 - 对应数据库 customer 表
 * ============================================================================
 *
 * 【作用说明】
 * 客户模型用来管理访问网站并使用在线客服的用户信息。
 * 客户不需要注册账号，系统会自动为每个访客生成一个唯一标识(UUID)。
 *
 * 【客户 vs 客服的区别】
 * - 客户(Customer)：访问网站的用户，发起咨询的人
 * - 客服(Agent)：公司员工，回答咨询的人
 *
 * 【数据库字段说明】
 * @property int $id               主键ID
 * @property string $uuid          唯一标识符，存储在客户浏览器中
 * @property string $ip            客户IP地址
 * @property string $user_agent    浏览器信息字符串
 * @property string $source_url    来源页面URL（客户从哪个页面发起咨询）
 * @property string $referrer      引荐来源URL（客户从哪个网站跳转过来）
 * @property string $device_type   设备类型：PC/Mobile/Tablet
 * @property string $browser       浏览器名称：Chrome/Safari/Firefox等
 * @property string $os            操作系统：Windows/macOS/iOS/Android等
 * @property string $city          所在城市（根据IP解析）
 * @property string $nickname      客户昵称（可选）
 * @property string $email         客户邮箱（由客服手动填写）
 * @property string $timezone      客户时区（自动获取，如 Asia/Shanghai）
 * @property string $last_active_at 最后活跃时间
 * @property string $created_at    首次访问时间
 */
class Customer extends Model
{
    /**
     * 指定对应的数据库表名
     */
    protected ?string $table = 'customer';

    /**
     * 禁用 updated_at 字段
     *
     * 【说明】
     * 客户表不需要更新时间字段，因为我们用 last_active_at 来记录最后活跃时间。
     * 设置为 null 后，框架就不会自动维护 updated_at 字段了。
     */
    public const UPDATED_AT = null;

    /**
     * 允许批量赋值的字段
     */
    protected array $fillable = [
        'uuid',
        'ip',
        'user_agent',
        'source_url',
        'referrer',
        'device_type',
        'browser',
        'os',
        'city',
        'nickname',
        'email',
        'timezone',
        'last_active_at',
    ];

    /**
     * 字段类型转换
     */
    protected array $casts = [
        'id' => 'integer',
    ];

    /**
     * 根据UUID查找或创建客户
     *
     * 【核心业务方法】
     * 这是客户模型最重要的方法。当客户连接WebSocket时，系统会调用这个方法：
     * - 如果是新客户（UUID不存在）：创建新的客户记录
     * - 如果是老客户（UUID已存在）：更新最后活跃时间
     *
     * 【UUID是什么？】
     * UUID(通用唯一标识符)是一个随机生成的字符串，格式如：
     * "550e8400-e29b-41d4-a716-446655440000"
     * 第一次访问时生成，存储在浏览器的 localStorage 中，下次访问时带上。
     *
     * @param string $uuid 客户唯一标识
     * @param string $ip IP地址
     * @param string $userAgent 浏览器User-Agent字符串
     * @param array $extraInfo 额外信息（来源页面、设备类型等）
     * @return self 客户对象
     *
     * @example
     * $customer = Customer::findOrCreateByUuid(
     *     'uuid-xxx-xxx',
     *     '192.168.1.1',
     *     'Mozilla/5.0...',
     *     ['source_url' => 'https://example.com/product', 'device_type' => 'Mobile']
     * );
     */
    public static function findOrCreateByUuid(string $uuid, string $ip = '', string $userAgent = '', array $extraInfo = []): self
    {
        // 准备创建数据
        $createData = [
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 500),
        ];
        if (!empty($extraInfo)) {
            $createData = array_merge($createData, array_filter($extraInfo));
        }

        // 使用 firstOrCreate 原子操作，避免并发问题
        // 如果 UUID 已存在则返回现有记录，否则创建新记录
        try {
            $customer = self::query()->firstOrCreate(
                ['uuid' => $uuid],  // 查询条件
                $createData         // 创建时的额外数据
            );
        } catch (\Throwable $e) {
            // 处理极端并发情况下的重复键异常
            // 再次尝试查询（此时记录应该已存在）
            $customer = self::query()->where('uuid', $uuid)->first();
            if (!$customer) {
                throw $e; // 如果还是找不到，抛出原异常
            }
        }

        // 如果是老客户（记录已存在），更新活跃时间
        if (!$customer->wasRecentlyCreated) {
            $updateData = [
                'last_active_at' => date('Y-m-d H:i:s'),
                'ip' => $ip ?: $customer->ip,
            ];
            // 只更新之前为空的字段（不覆盖已有数据）
            foreach ($extraInfo as $key => $value) {
                if (!empty($value) && empty($customer->$key)) {
                    $updateData[$key] = $value;
                }
            }
            $customer->update($updateData);
        }

        return $customer;
    }

    /**
     * 获取设备信息描述
     *
     * 【使用场景】
     * 在客服工作台显示客户的设备信息，帮助客服了解客户的使用环境。
     *
     * @return string 设备信息，如 "Mobile / iOS / Safari"
     *
     * @example
     * echo $customer->getDeviceInfo(); // 输出: "PC / Windows / Chrome"
     */
    public function getDeviceInfo(): string
    {
        $parts = [];
        if ($this->device_type) $parts[] = $this->device_type;
        if ($this->os) $parts[] = $this->os;
        if ($this->browser) $parts[] = $this->browser;
        return implode(' / ', $parts) ?: '未知';
    }
}

