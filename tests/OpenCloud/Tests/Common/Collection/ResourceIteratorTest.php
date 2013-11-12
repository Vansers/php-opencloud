<?php
/**
 * PHP OpenCloud library.
 * 
 * @copyright 2013 Rackspace Hosting, Inc. See LICENSE for information.
 * @license   https://www.apache.org/licenses/LICENSE-2.0
 * @author    Jamie Hannaford <jamie.hannaford@rackspace.com>
 */

namespace OpenCloud\Tests\Common\Collection;

use OpenCloud\Tests\OpenCloudTestCase;
use OpenCloud\Common\Collection\ResourceIterator;

class ResourceIteratorTest extends OpenCloudTestCase
{

    public function test_Factory()
    {
        $service = $this->getClient()->computeService();

        $this->addMockSubscriber($this->getTestFilePath('Flavors', 'Compute', true));

        $iterator = ResourceIterator::factory($service, $service->getUrl('flavors'), array(
            'resourceClass' => 'Flavor',
            'collectionKey' => 'flavors',
            'markerKey'     => 'id',
            'linksKey'      => 'flavors_key'
        ));

        $item = $iterator->getElement(0);

        $this->assertInstanceOf('OpenCloud\Compute\Resource\Flavor', $item);
        $this->assertEquals('512MB Standard Instance', $item->getName());
        $this->assertEquals(7, $iterator->count());
    }

}