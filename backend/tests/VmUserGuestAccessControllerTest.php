<?php

declare(strict_types=1);

use App\App;
use App\Chat\Role;
use App\Permissions;
use App\Chat\Permission;
use PHPUnit\Framework\TestCase;
use App\Helpers\PermissionHelper;
use Symfony\Component\HttpFoundation\Request;
use App\Controllers\User\Vds\VmUserGuestAccessController;

final class VmUserGuestAccessControllerTest extends TestCase
{
    private array $roles = [];
    private array $permissions = [];

    protected function setUp(): void
    {
        App::getInstance(false, true, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->permissions as $id) {
            Permission::deletePermission($id);
        }
        foreach ($this->roles as $id) {
            Role::deleteRole($id);
        }
        PermissionHelper::resetRequestCache();
    }

    public function testRootCanManageGuestAccountsOnAnotherUsersLxcAndQemu(): void
    {
        $actor = $this->actor('root', Permissions::ADMIN_ROOT);
        foreach (['lxc', 'qemu'] as $type) {
            $info = $this->request($actor, $type);
            self::assertTrue($info['data']['can_manage_accounts']);
            foreach (['reveal', 'reset', 'add'] as $action) {
                $result = $this->request($actor, $type, $action);
                // The fixture deliberately has no backing guest. Authorization must pass
                // and reach the instance check before any guest connection is attempted.
                self::assertSame('The instance is currently unavailable', $result['message']);
            }
        }
    }

    public function testVdsViewPermissionDoesNotGrantPasswordAccess(): void
    {
        $staff = $this->actor('staff', Permissions::ADMIN_VM_INSTANCES_VIEW);
        $info = $this->request($staff, 'lxc');
        self::assertFalse($info['data']['can_manage_accounts']);

        foreach (['reveal', 'reset', 'add'] as $action) {
            $result = $this->request($staff, 'lxc', $action);
            self::assertSame('You do not have access to guest accounts for this instance', $result['message']);
        }
    }

    public function testOwnerCanManageWithoutAnAdminPermission(): void
    {
        $owner = $this->actor('owner');
        self::assertTrue($this->request($owner, 'lxc')['data']['can_manage_accounts']);
        $stranger = $this->actor('stranger');
        self::assertFalse($this->request($stranger, 'lxc')['success']);
    }

    private function actor(string $name, ?string $permission = null): array
    {
        $role = Role::createRole([
            'name' => 'guest-access-test-' . $name,
            'display_name' => 'Guest Access Test',
            'color' => '#123456',
        ]);
        self::assertIsInt($role);
        $this->roles[] = $role;
        if ($permission !== null) {
            $id = Permission::createPermission(['permission' => $permission, 'role_id' => $role]);
            self::assertIsInt($id);
            $this->permissions[] = $id;
        }
        $actor = ['uuid' => $name . '-guest-access-fixture', 'role_id' => $role, 'username' => $name];
        PermissionHelper::rememberUser($actor);

        return $actor;
    }

    private function request(array $actor, string $type, ?string $action = null): array
    {
        $request = Request::create('/api/user/vm-instances/2147483647/guest-access', $action === null ? 'GET' : 'POST',
            content: $action === null ? null : json_encode(['action' => $action, 'username' => 'fixture']));
        $request->attributes->set('user', $actor);
        $request->attributes->set('vmInstance', [
            'id' => 2147483647, 'vm_type' => $type, 'vm_node_id' => 0, 'vmid' => 0,
            'user_uuid' => 'owner-guest-access-fixture', 'hostname' => 'fixture', 'ip_address' => null,
        ]);

        return json_decode((new VmUserGuestAccessController())->handle($request)->getContent(), true);
    }
}
