<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Dependencies;

use KnowKit\Domain as D;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';
require_once dirname(__DIR__,2).'/retrieval/lexical/LexicalFixture.php';

#[Group('lifecycle:dependencies')]
final class OptionalExtensionTest extends TestCase
{
    public function testCoreLexicalEvidenceWorksWithoutGraphNavigationOrSummaries(): void
    {
        LexicalFixture::run(function($f) {
            $query=new D\Query('gravity'); $scope=$f->scope(['allowed'],query:$query);
            $hits=$f->index->search($scope,$query,5);
            self::assertContains('allowed',$f->names($hits));
            self::assertSame(0,$f->catalog->count('dependency_nodes'));
            self::assertSame($f->sets['allowed']->chunkSet->chunks[0]->ref,$hits->candidates[0]->ref);
            $core=json_decode(file_get_contents(dirname(__DIR__,3).'/packages/core/composer.json'),true,512,JSON_THROW_ON_ERROR);
            foreach(array_keys($core['require']) as $dependency) { self::assertTrue($dependency==='php' || str_starts_with($dependency,'ext-')); }
        });
    }
}
