<?php
/**
 * PHP OpenCloud library.
 *
 * @copyright 2013 Rackspace Hosting, Inc. See LICENSE for information.
 * @license   https://www.apache.org/licenses/LICENSE-2.0
 * @author    Jamie Hannaford <jamie.hannaford@rackspace.com>
 * @author    Glen Campbell <glen.campbell@rackspace.com>
 */

namespace OpenCloud\ObjectStore\Resource;

use Guzzle\Http\EntityBody;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Guzzle\Http\Url;
use OpenCloud\Common\Collection;
use OpenCloud\Common\Constants\Size;
use OpenCloud\Common\Exceptions;
use OpenCloud\Common\Http\Message\Response;
use OpenCloud\Common\Service\AbstractService;
use OpenCloud\ObjectStore\Constants\Header as HeaderConst;
use OpenCloud\ObjectStore\Upload\TransferBuilder;
use OpenCloud\Common\Http\Message\Formatter;

/**
 * A container is a storage compartment for your data and provides a way for you 
 * to organize your data. You can think of a container as a folder in Windows 
 * or a directory in Unix. The primary difference between a container and these 
 * other file system concepts is that containers cannot be nested.
 * 
 * A container can also be CDN-enabled (for public access), in which case you
 * will need to interact with a CDNContainer object instead of this one.
 */
class Container extends AbstractContainer
{
    const METADATA_LABEL = 'Container';
    
    /**
     * This is the object that holds all the CDN functionality. This Container therefore acts as a simple wrapper and is
     * interested in storage concerns only.
     *
     * @var CDNContainer|null
     */
    private $cdn;

    public function __construct(AbstractService $service, $data = null)
    {
        parent::__construct($service, $data);

        // Set metadata items for collection listings
        if (isset($data->count)) {
            $this->metadata->setProperty('Object-Count', $data->count);
        }
        if (isset($data->bytes)) {
            $this->metadata->setProperty('Bytes-Used', $data->bytes);
        }
    }

    /**
     * Factory method that instantiates an object from a Response object.
     *
     * @param Response        $response
     * @param AbstractService $service
     * @return static
     */
    public static function fromResponse(Response $response, AbstractService $service)
    {
        $self = parent::fromResponse($response, $service);
        
        $segments = Url::factory($response->getEffectiveUrl())->getPathSegments();
        $self->name = end($segments);
        
        return $self;
    }

    /**
     * Get the CDN object.
     *
     * @return null|CDNContainer
     * @throws \OpenCloud\Common\Exceptions\CdnNotAvailableError
     */
    public function getCdn()
    {
        if (!$this->isCdnEnabled() || !$this->cdn) {
            throw new Exceptions\CdnNotAvailableError(
            	'Either this container is not CDN-enabled or the CDN is not available'
            );
        }
        
        return $this->cdn;
    }

    /**
     * It would be awesome to put these convenience methods (which are identical to the ones in the Account object) in
     * a trait, but we have to wait for v5.3 EOL first...
     *
     * @return null|string|int
     */
    public function getObjectCount()
    {
        return $this->metadata->getProperty('Object-Count');
    }

    /**
     * @return null|string|int
     */
    public function getBytesUsed()
    {
        return $this->metadata->getProperty('Bytes-Used');
    }

    /**
     * @param $value
     * @return mixed
     */
    public function setCountQuota($value)
    {
        $this->metadata->setProperty('Quota-Count', $value);
        return $this->saveMetadata($this->metadata->toArray());
    }

    /**
     * @return null|string|int
     */
    public function getCountQuota()
    {
        return $this->metadata->getProperty('Quota-Count');
    }

    /**
     * @param $value
     * @return mixed
     */
    public function setBytesQuota($value)
    {
        $this->metadata->setProperty('Quota-Bytes', $value);
        return $this->saveMetadata($this->metadata->toArray());
    }

    /**
     * @return null|string|int
     */
    public function getBytesQuota()
    {
        return $this->metadata->getProperty('Quota-Bytes');
    }
    
    public function delete($deleteObjects = false)
    {
        if ($deleteObjects === true) {
            $this->deleteAllObjects();
        }

        return $this->getClient()->delete($this->getUrl())
            ->setExceptionHandler(array(
                404 => 'Container not found',
                409 => 'Container must be empty before deleting. Please set the $deleteObjects argument to TRUE.',
                300 => 'Unknown error'
            ))
            ->send();
    }

    /**
     * Deletes all objects that this container currently contains. Useful when doing operations (like a delete) that
     * require an empty container first.
     *
     * @return mixed
     */
    public function deleteAllObjects()
    {
        $requests = array();
        
        $list = $this->objectList();
        
        while ($object = $list->next()) {
            $requests[] = $this->getClient()->delete($object->getUrl());
        }

        return $this->getClient()->send($requests);
    }
    
    /**
     * Creates a Collection of objects in the container
     *
     * @param array $params associative array of parameter values.
     * * account/tenant - The unique identifier of the account/tenant.
     * * container- The unique identifier of the container.
     * * limit (Optional) - The number limit of results.
     * * marker (Optional) - Value of the marker, that the object names
     *      greater in value than are returned.
     * * end_marker (Optional) - Value of the marker, that the object names
     *      less in value than are returned.
     * * prefix (Optional) - Value of the prefix, which the returned object
     *      names begin with.
     * * format (Optional) - Value of the serialized response format, either
     *      json or xml.
     * * delimiter (Optional) - Value of the delimiter, that all the object
     *      names nested in the container are returned.
     * @link http://api.openstack.org for a list of possible parameter
     *      names and values
     * @return 'OpenCloud\Common\Collection
     * @throws ObjFetchError
     */
    public function objectList(array $params = array())
    {
        $params['format'] = 'json';

        $response = $this->getClient()
            ->get($this->getUrl(null, $params))
            ->send();

        $body = Formatter::decode($response);

        return new Collection($this, 'OpenCloud\ObjectStore\Resource\DataObject', $body);
    }

    /**
     * Turn on access logs, which track all the web traffic that your data objects accrue.
     *
     * @return \Guzzle\Http\Message\Response
     */
    public function enableLogging()
    {
        return $this->saveMetadata($this->appendToMetadata(array(
            HeaderConst::ACCESS_LOGS => true
        )));
    }

    /**
     * Disable access logs.
     *
     * @return \Guzzle\Http\Message\Response
     */
    public function disableLogging()
    {
        return $this->saveMetadata($this->appendToMetadata(array(
            HeaderConst::ACCESS_LOGS => false
        )));
    }

    /**
     * Enable this container for public CDN access.
     *
     * @param null $ttl
     */
    public function enableCdn($ttl = null)
    {
        $headers = array('X-CDN-Enabled' => 'True');
        if ($ttl) {
            $headers['X-TTL'] = (int) $ttl;
        }

        $this->getClient()->put($this->getCdnService()->getUrl($this->name), $headers)->send();
        $this->refresh();
    }

    /**
     * Disables the containers CDN function. Note that the container will still 
     * be available on the CDN until its TTL expires.
     * 
     * @return \Guzzle\Http\Message\Response
     */
    public function disableCdn()
    {
        return $this->getClient()
            ->put($this->getCdnService()->getUrl($this->name), array('X-CDN-Enabled' => 'False'))
            ->send();
    }

    public function refresh($id = null, $url = null)
    {
        $headers = $this->createRefreshRequest()->send()->getHeaders();
        $this->setMetadata($headers, true);
        
        try {
            
            $cdn = new CDNContainer($this->getService()->getCDNService());
            $cdn->setName($this->name);
            
            $response = $cdn->createRefreshRequest()->send();
            
            if ($response->isSuccessful()) {
                $this->cdn = $cdn;
                $this->cdn->setMetadata($response->getHeaders(), true);
            }
            
        } catch (ClientErrorResponseException $e) {}   
    }

    /**
     * Get either a fresh data object (no $info), or get an existing one by passing in data for population.
     *
     * @param  mixed $info
     * @return DataObject
     */
    public function dataObject($info = null)
    {
        return new DataObject($this, $info);
    }
    
    /**
     * Retrieve an object from the API. Apart from using the name as an 
     * identifier, you can also specify additional headers that will be used 
     * fpr a conditional GET request. These are
     * 
     * * `If-Match'
     * * `If-None-Match'
     * * `If-Modified-Since'
     * * `If-Unmodified-Since'
     * * `Range'  For example: 
     *      bytes=-5    would mean the last 5 bytes of the object
     *      bytes=10-15 would mean 5 bytes after a 10 byte offset
     *      bytes=32-   would mean all dat after first 32 bytes
     * 
     * These are also documented in RFC 2616.
     * 
     * @param string $name
     * @param array $headers
     * @return DataObject
     */
    public function getObject($name, array $headers = array())
    {
        $response = $this->getClient()
            ->get($this->getUrl($name), $headers)
            ->send();

        return $this->dataObject()->setName($name)
            ->setContent($response->getBody())
            ->setMetadata($response->getHeaders(), true);
    }

    /**
     * Upload a single file to the API.
     *
     * @param       $name    Name that the file will be saved as in your container.
     * @param       $data    Either a string or stream representation of the file contents to be uploaded.
     * @param array $headers Optional headers that will be sent with the request (useful for object metadata).
     * @return DataObject
     */
    public function uploadObject($name, $data, array $headers = array())
    {
        $entityBody = EntityBody::factory($data);

        $url = clone $this->getUrl();
        $url->addPath($name);

        $this->getClient()->put($url, $headers, $entityBody)->send();

        return $this->getObject($name);
    }

    /**
     * Upload an array of objects for upload. This method optimizes the upload procedure by batching requests for
     * faster execution. This is a very useful procedure when you just have a bunch of unremarkable files to be
     * uploaded quickly. Each file must be under 5GB.
     *
     * @param array $files With the following array structure:
     *                      `name' Name that the file will be saved as in your container. Required.
     *                      `path' Path to an existing file, OR
     *                      `body' Either a string or stream representation of the file contents to be uploaded.
     * @param array $headers Optional headers that will be sent with the request (useful for object metadata).
     *
     * @throws \OpenCloud\Common\Exceptions\InvalidArgumentError
     * @return \Guzzle\Http\Message\Response
     */
    public function uploadObjects(array $files, array $commonHeaders = array())
    {
        $requests = array();
        
        foreach ($files as $entity) {
            
            if (empty($entity['name'])) {
	            throw new Exceptions\InvalidArgumentError('You must provide a name.');
	        }
            
            if (!empty($entity['path']) && file_exists($entity['path'])) {
            	$body = fopen($entity['path'], 'r+');
	        } elseif (!empty($entity['body'])) {
	            $body = $entity['body'];
	        } else {
	            throw new Exceptions\InvalidArgumentError('You must provide either a readable path or a body');
	        }
	        
            $entityBody = EntityBody::factory($body);
            
            // @codeCoverageIgnoreStart
            if ($entityBody->getContentLength() >= 5 * Size::GB) {
                throw new Exceptions\InvalidArgumentError(
                    'For multiple uploads, you cannot upload more than 5GB per '
                    . ' file. Use the UploadBuilder for larger files.'
                );
            }
            // @codeCoverageIgnoreEnd

            // Allow custom headers and common
            $headers = (isset($entity['headers'])) ? $entity['headers'] : $commonHeaders;

            $url = clone $this->getUrl();
            $url->addPath($entity['name']);

            $requests[] = $this->getClient()->put($url, $headers, $entityBody);
        }
        
        return $this->getClient()->send($requests);
    }

    /**
     * When uploading large files (+5GB), you need to upload the file as chunks using multibyte transfer. This method
     * sets up the transfer, and in order to execute the transfer, you need to call upload() on the returned object.
     *
     * @param array Options
     * @see \OpenCloud\ObjectStore\Upload\UploadBuilder::setOptions for a list of accepted options.
     * @throws \OpenCloud\Common\Exceptions\InvalidArgumentError
     * @return mixed
     */
    public function setupObjectTransfer(array $options = array())
    {
        // Name is required
        if (empty($options['name'])) {
            throw new Exceptions\InvalidArgumentError('You must provide a name.');
        }

        // As is some form of entity body
        if (!empty($options['path']) && file_exists($options['path'])) {
            $body = fopen($options['path'], 'r+');
        } elseif (!empty($options['body'])) {
            $body = $options['body'];
        } else {
            throw new Exceptions\InvalidArgumentError('You must provide either a readable path or a body');
        }
        
        // Build upload
        $transfer = TransferBuilder::newInstance()
            ->setOption('objectName', $options['name'])
            ->setEntityBody(EntityBody::factory($body))
            ->setContainer($this);
        
        // Add extra options
        if (!empty($options['metadata'])) {
            $transfer->setOption('metadata', $options['metadata']);
        }
        if (!empty($options['partSize'])) {
            $transfer->setOption('partSize', $options['partSize']);
        }
        if (!empty($options['concurrency'])) {
            $transfer->setOption('concurrency', $options['concurrency']);
        }
        if (!empty($options['progress'])) {
            $transfer->setOption('progress', $options['progress']);
        }

        return $transfer->build();
    }

}