<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\UserListCriteria;
use PHPUnit\Framework\TestCase;

class UserListCriteriaTest extends TestCase
{
    public function testTrimsAndNullifiesEmptySearch(): void
    {
        $criteria = UserListCriteria::fromRequest('  ', null, null, null, 1);
        $this->assertNull($criteria->search);

        $criteria = UserListCriteria::fromRequest('  Jan  ', null, null, null, 1);
        $this->assertSame('Jan', $criteria->search);
    }

    public function testInvalidFilterFallsBackToNull(): void
    {
        $this->assertNull(UserListCriteria::fromRequest(null, 'garbage', null, null, 1)->filter);
        $this->assertSame('overdue', UserListCriteria::fromRequest(null, 'overdue', null, null, 1)->filter);
    }

    public function testUnknownSortColumnFallsBackToCreatedDesc(): void
    {
        $criteria = UserListCriteria::fromRequest(null, null, 'drop_table', 'sideways', 1);

        $this->assertSame('created', $criteria->sortColumn);
        $this->assertSame('desc', $criteria->sortDirection);
        $this->assertSame('u.created_at', $criteria->sortExpression());
    }

    public function testWhitelistedSortColumnAndAscDirection(): void
    {
        $criteria = UserListCriteria::fromRequest(null, null, 'mrr', 'asc', 1);

        $this->assertSame('mrr', $criteria->sortColumn);
        $this->assertSame('asc', $criteria->sortDirection);
        $this->assertSame('mrr', $criteria->sortExpression());
    }

    public function testPageIsClampedToAtLeastOne(): void
    {
        $this->assertSame(1, UserListCriteria::fromRequest(null, null, null, null, 0)->page);
        $this->assertSame(1, UserListCriteria::fromRequest(null, null, null, null, -5)->page);
        $this->assertSame(3, UserListCriteria::fromRequest(null, null, null, null, 3)->page);
    }
}
