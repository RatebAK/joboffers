# Database Seeders

## UserSeeder

The `UserSeeder` creates test users for development and testing purposes with different roles for the JWT role-based authentication system.

### Created Test Users

| Email | Password | Roles | Description |
|-------|----------|-------|-------------|
| admin@example.com | Admin@123 | admin | System administrator |
| employer@example.com | Employer@123 | employer | Company recruiter |
| employee@example.com | Employee@123 | employee | Job seeker |
| multirole@example.com | MultiRole@123 | admin, employer, employee | User with all roles |
| jane.employee@example.com | Employee@123 | employee | Additional job seeker |
| recruiter@startupco.com | Employer@123 | employer | Additional employer |
| sysadmin@example.com | Admin@123 | admin | Additional admin |
| freelancer@example.com | Freelancer@123 | employer, employee | Dual role user |

### Usage

Run the seeder individually:
```bash
php artisan db:seed --class=UserSeeder
```

Run all seeders (includes UserSeeder):
```bash
php artisan db:seed
```

Fresh migration with seeding:
```bash
php artisan migrate:fresh --seed
```

### UserFactory Enhancements

The `UserFactory` has been enhanced with convenient methods for creating users with specific roles:

```php
// Create users with specific roles
User::factory()->admin()->create();
User::factory()->employer()->create();
User::factory()->employee()->create();
User::factory()->multiRole()->create(); // employer + employee
User::factory()->withRoles(['admin', 'employer'])->create();

// Create multiple users
User::factory(5)->employee()->create();
```

### Testing Integration

These test users are designed to support comprehensive testing of the JWT role-based authentication system:

- **Requirements 1.1**: User registration with role assignment
- **Requirements 8.1**: Multi-role support testing

The seeder provides users for testing all authentication flows, role-based access control, and multi-role scenarios.