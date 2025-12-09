<?php

declare(strict_types=1);

namespace App\Model;

/**
 * ============================================================================
 * 客服模型 - 对应数据库 agent 表
 * ============================================================================
 *
 * 【作用说明】
 * 客服模型用来管理客服人员的信息，包括：
 * - 登录账号和密码
 * - 昵称和头像
 * - 最大接待会话数
 * - 账号状态（启用/禁用）
 * - 是否是管理员
 *
 * 【数据库字段说明】
 * @property int $id               主键ID，自动递增
 * @property string $username      登录用户名，用于登录系统
 * @property string $password      登录密码，存储的是加密后的密码
 * @property string $nickname      显示昵称，客户看到的客服名称
 * @property string $avatar        头像URL地址
 * @property int $max_sessions     最大同时接待会话数，防止客服过载
 * @property int $status           账号状态：1=启用，0=禁用
 * @property int $is_admin         是否管理员：1=是，0=否
 * @property string $created_at    创建时间
 * @property string $updated_at    最后更新时间
 */
class Agent extends Model
{
    /**
     * 指定对应的数据库表名
     * 如果不指定，框架会自动把类名转换成表名（Agent -> agents）
     */
    protected ?string $table = 'agent';

    /**
     * 允许批量赋值的字段
     *
     * 【安全说明】
     * 只有在这个数组中的字段才能通过 create() 或 update() 批量赋值。
     * 这是为了防止恶意用户通过修改请求参数来篡改不应该修改的字段。
     * 比如 id 和 created_at 就不在这里，防止被篡改。
     */
    protected array $fillable = [
        'username',
        'password',
        'nickname',
        'avatar',
        'max_sessions',
        'status',
        'is_admin',
    ];

    /**
     * 隐藏字段 - 转换成数组或JSON时不显示
     *
     * 【安全说明】
     * 密码是敏感信息，即使是加密后的密码也不应该返回给前端。
     * 把 password 放在这里，查询结果转JSON时会自动过滤掉。
     */
    protected array $hidden = [
        'password',
    ];

    /**
     * 字段类型转换
     *
     * 【说明】
     * 数据库返回的数据都是字符串类型，这里定义哪些字段需要转换成整数。
     * 比如 status 在数据库是 '1'，转换后变成数字 1，方便代码中做判断。
     */
    protected array $casts = [
        'id' => 'integer',
        'max_sessions' => 'integer',
        'status' => 'integer',
        'is_admin' => 'integer',
    ];

    /**
     * 验证密码是否正确
     *
     * 【使用场景】
     * 客服登录时，用户输入的密码需要和数据库中存储的加密密码进行比对。
     *
     * 【原理说明】
     * password_verify() 是PHP内置函数，可以验证明文密码和加密密码是否匹配。
     *
     * @param string $password 用户输入的明文密码
     * @return bool 密码是否正确
     *
     * @example
     * $agent = Agent::where('username', 'admin')->first();
     * if ($agent->verifyPassword('123456')) {
     *     echo '密码正确';
     * }
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * 设置密码 - 自动加密
     *
     * 【说明】
     * 这是一个"属性访问器"(Mutator)，当给 password 字段赋值时会自动触发。
     * 明文密码会被自动加密后再存入数据库。
     *
     * 【原理说明】
     * password_hash() 是PHP内置函数，使用安全的加密算法对密码进行加密。
     * 加密后的密码形如：$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
     *
     * @param string $value 明文密码
     *
     * @example
     * $agent = new Agent();
     * $agent->password = '123456';  // 自动加密
     * $agent->save();
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * 判断账号是否启用
     *
     * @return bool true=启用，false=禁用
     */
    public function isEnabled(): bool
    {
        return $this->status === 1;
    }

    /**
     * 判断是否是管理员
     *
     * 【管理员特权】
     * - 可以转移任何客服的会话
     * - 可以查看所有客服的统计数据
     * - 可以查看所有历史会话
     *
     * @return bool true=是管理员，false=普通客服
     */
    public function isAdmin(): bool
    {
        return $this->is_admin === 1;
    }
}

