<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\PreRegisteredUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AdminImportPreRegisteredUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function createAdmin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin@map.org',
            'password' => Hash::make('password123'),
            'department' => 'ADMIN DEPARTMENT',
            'position' => 'Head',
            'layer' => 'Other',
            'is_verified' => true,
        ]);
    }

    protected function createRegularUser(): User
    {
        return User::create([
            'name' => 'Regular User',
            'email' => 'regular@map.org',
            'password' => Hash::make('password123'),
            'department' => 'RESEARCH DEPARTMENT',
            'position' => 'Junior',
            'layer' => 'Other',
            'is_verified' => true,
        ]);
    }

    protected function createExcelFile(array $rows, string $filename = 'test_users.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows);

        $tempPath = tempnam(sys_get_temp_dir(), 'test_xlsx_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile(
            $tempPath,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    protected function createCsvFile(array $rows, string $filename = 'test_users.csv'): UploadedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_csv_') . '.csv';
        $handle = fopen($tempPath, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return new UploadedFile(
            $tempPath,
            $filename,
            'text/csv',
            null,
            true
        );
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'User One', 'user1@example.com', 'IT & AI DEPARTMENT', 'Junior', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $regularUser = $this->createRegularUser();
        Sanctum::actingAs($regularUser);

        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'User One', 'user1@example.com', 'IT & AI DEPARTMENT', 'Junior', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Unauthorized. Admin access required.',
            ]);
    }

    public function test_validation_requires_file(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/users/import', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_validation_rejects_invalid_file_mimes(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $invalidFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $invalidFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_admin_can_import_excel_invitations_successfully(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'Sami Ahmad', 'sami@example.com', 'IT & AI DEPARTMENT', 'Junior', 'Other'],
            ['Pause', 'Lina Qader', 'lina@example.com', 'RESEARCH DEPARTMENT', 'Head', 'social factor'],
            ['Stop', 'Kareem Omar', 'kareem@example.com', 'DESIGN DEPARTMENT', 'Corrdinator', 'urban planning'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'User invitations imported successfully.',
                'summary' => [
                    'total_rows' => 3,
                    'imported' => 3,
                    'updated' => 0,
                    'already_registered' => 0,
                    'duplicates_in_file' => 0,
                    'skipped_invalid' => 0,
                ],
                'errors' => [],
            ]);

        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'sami@example.com',
            'name' => 'Sami Ahmad',
            'department' => 'IT & AI DEPARTMENT',
            'position' => 'Junior',
            'status' => 'Active',
            'layer' => 'Other',
        ]);

        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'lina@example.com',
            'name' => 'Lina Qader',
            'department' => 'RESEARCH DEPARTMENT',
            'position' => 'Head',
            'status' => 'Pause',
            'layer' => 'social factor',
        ]);

        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'kareem@example.com',
            'status' => 'Stop',
        ]);
    }

    public function test_admin_import_prevents_duplicate_emails_in_the_same_file(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'Duplicate User', 'dup@example.com', 'IT & AI DEPARTMENT', 'Junior', 'Other'],
            ['Active', 'Duplicate User Again', 'dup@example.com', 'DESIGN DEPARTMENT', 'Head', 'Other'],
            ['Active', 'Duplicate User Third', 'DUP@EXAMPLE.COM', 'RESEARCH DEPARTMENT', 'Senior Leader', 'Other'],
            ['Active', 'Unique User', 'unique@example.com', 'EDUCATION DEPARTMENT', 'Junior', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'summary' => [
                    'total_rows' => 4,
                    'imported' => 2,
                    'duplicates_in_file' => 2,
                ],
            ]);

        $this->assertEquals(1, PreRegisteredUser::where('email', 'dup@example.com')->count());
        $this->assertEquals(1, PreRegisteredUser::where('email', 'unique@example.com')->count());
    }

    public function test_admin_import_updates_existing_pre_registered_user_by_default(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        PreRegisteredUser::create([
            'name' => 'Old Name',
            'email' => 'existing@example.com',
            'department' => 'MEDIA DEPARTMENT',
            'position' => 'Junior',
            'layer' => 'Other',
            'status' => 'Pause',
        ]);

        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'New Name', 'existing@example.com', 'IT & AI DEPARTMENT', 'Head', 'technology and infrastructure'],
            ['Active', 'Brand New', 'brandnew@example.com', 'RESEARCH DEPARTMENT', 'Junior', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
            'update_existing' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'summary' => [
                    'total_rows' => 2,
                    'imported' => 1,
                    'updated' => 1,
                ],
            ]);

        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'existing@example.com',
            'name' => 'New Name',
            'department' => 'IT & AI DEPARTMENT',
            'position' => 'Head',
            'layer' => 'technology and infrastructure',
            'status' => 'Active',
        ]);
    }

    public function test_admin_import_skips_existing_when_update_existing_is_false(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        PreRegisteredUser::create([
            'name' => 'Keep This Name',
            'email' => 'keep@example.com',
            'department' => 'DESIGN DEPARTMENT',
            'position' => 'Junior',
            'layer' => 'Other',
            'status' => 'Pause',
        ]);

        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'Change Attempt', 'keep@example.com', 'IT & AI DEPARTMENT', 'Head', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
            'update_existing' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'summary' => [
                    'total_rows' => 1,
                    'imported' => 0,
                    'updated' => 0,
                ],
            ]);

        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'keep@example.com',
            'name' => 'Keep This Name',
            'department' => 'DESIGN DEPARTMENT',
            'position' => 'Junior',
            'status' => 'Pause',
        ]);
    }

    public function test_admin_import_skips_users_already_registered_in_users_table(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        User::create([
            'name' => 'Registered Volunteer',
            'email' => 'volunteer@reparametrize.org',
            'password' => Hash::make('password123'),
            'department' => 'IT & AI DEPARTMENT',
            'position' => 'Junior',
            'layer' => 'Other',
            'is_verified' => true,
        ]);

        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'Registered Volunteer', 'volunteer@reparametrize.org', 'IT & AI DEPARTMENT', 'Junior', 'Other'],
            ['Active', 'New Candidate', 'candidate@reparametrize.org', 'RESEARCH DEPARTMENT', 'Junior', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'summary' => [
                    'total_rows' => 2,
                    'imported' => 1,
                    'already_registered' => 1,
                ],
            ]);

        $this->assertDatabaseMissing('pre_registered_users', [
            'email' => 'volunteer@reparametrize.org',
        ]);
        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'candidate@reparametrize.org',
        ]);
    }

    public function test_admin_import_skips_empty_emails_and_records_invalid_email_format(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'Valid User', 'valid@example.com', 'IT & AI DEPARTMENT', 'Junior', 'Other'],
            ['Active', 'Empty Email User', '', 'RESEARCH DEPARTMENT', 'Junior', 'Other'],
            ['Active', 'Broken Email User', 'not-a-valid-email', 'DESIGN DEPARTMENT', 'Junior', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'summary' => [
                    'total_rows' => 3,
                    'imported' => 1,
                    'skipped_invalid' => 2,
                ],
            ]);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Invalid email format', $errors[0]);
    }

    public function test_admin_import_supports_csv_file(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $file = $this->createCsvFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'Csv User', 'csv_user@example.com', 'RESEARCH DEPARTMENT', 'Junior', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'summary' => [
                    'total_rows' => 1,
                    'imported' => 1,
                ],
            ]);

        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'csv_user@example.com',
        ]);
    }

    public function test_admin_import_works_via_admin_import_datasheet_route_alias(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $file = $this->createExcelFile([
            ['Status', 'name', 'Email', 'DEPARTMENT', 'Your Position', 'Layer'],
            ['Active', 'Alias User', 'alias_user@example.com', 'IT & AI DEPARTMENT', 'Junior', 'Other'],
        ]);

        $response = $this->postJson('/api/admin/import-datasheet', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'summary' => [
                    'imported' => 1,
                ],
            ]);

        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'alias_user@example.com',
        ]);
    }

    public function test_admin_can_import_real_pre_registered_users_xlsx_file(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $filePath = database_path('seeders/data/pre_registered_users.xlsx');
        $this->assertFileExists($filePath);

        $uploadedFile = new UploadedFile(
            $filePath,
            'pre_registered_users.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->postJson('/api/admin/users/import', [
            'file' => $uploadedFile,
        ]);

        $response->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertEquals(438, $summary['total_rows']);
        $this->assertEquals(437, $summary['imported']);
        $this->assertEquals(0, $summary['duplicates_in_file']);
        $this->assertEquals(1, $summary['skipped_invalid']); // invalid syntax email

        $this->assertCount(1, $response->json('errors'));
        $this->assertStringContainsString('omer.kartal1187gmail.com', $response->json('errors')[0]);

        // Verify some imported users in database
        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'ameenabdoh2@gmail.con',
            'name' => 'Ameen Abdoh',
            'department' => 'RESEARCH DEPARTMENT',
            'status' => 'Pause',
        ]);

        $this->assertDatabaseHas('pre_registered_users', [
            'email' => 'leesana14@gmail.com',
            'name' => 'Sana ahmad Bajouri',
            'status' => 'Stop',
        ]);
    }
}
