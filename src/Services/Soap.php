<?php
namespace DreamFactory\Core\Soap\Services;

use DreamFactory\Core\Components\Cacheable;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Soap\Components\WsseAuthHeader;
use DreamFactory\Core\Soap\FunctionSchema;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Inflector;

/**
 * Class Soap
 *
 * @package DreamFactory\Core\Soap\Services
 */
class Soap extends BaseRestService
{
    use Cacheable;

    //*************************************************************************
    //* Members
    //*************************************************************************

    /**
     * @var string
     */
    protected $wsdl;
    /**
     * @var \SoapClient
     */
    protected $client;
    /**
     * @type bool
     */
    protected $cacheEnabled = false;
    /**
     * @type array
     */
    protected $functions = [];
    /**
     * @type array
     */
    protected $types = [];

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Create a new SoapService
     *
     * @param array $settings settings array
     *
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    public function __construct($settings)
    {
        parent::__construct($settings);
        $config = array_get($settings, 'config', []);
        $this->wsdl = array_get($config, 'wsdl');

        // Validate url setup
        if (empty($this->wsdl)) {
            // check for location and uri in options
            if (!isset($config['options']['location']) || !isset($config['options']['uri'])) {
                throw new \InvalidArgumentException('SOAP Services require either a WSDL or both location and URI to be configured.');
            }
        }
        $options = array_get($config, 'options', []);
        if (!is_array($options)) {
            $options = [];
        } else {
            foreach ($options as $key => $value) {
                if (!is_numeric($value)) {
                    if (defined($value)) {
                        $options[$key] = constant($value);
                    }
                }
            }
        }

        $this->cacheEnabled = ArrayUtils::getBool($config, 'cache_enabled');
        $this->cacheTTL = intval(array_get($config, 'cache_ttl', \Config::get('df.default_cache_ttl')));
        $this->cachePrefix = 'service_' . $this->id . ':';

        try {
            $this->client = @new \SoapClient($this->wsdl, $options);

            $headers = array_get($config, 'headers', []);
            $soapHeaders = null;

            foreach ($headers as $header) {
                $headerType = array_get($header, 'type', 'generic');
                switch ($headerType) {
                    case 'wsse':
                        $data = json_decode(stripslashes(array_get($header, 'data', '{}')), true);
                        $data = (is_null($data) || !is_array($data)) ? [] : $data;
                        $username = array_get($data, 'username');
                        $password = array_get($data, 'password');

                        if (!empty($username) && !empty($password)) {
                            $soapHeaders[] = new WsseAuthHeader($username, $password);
                        }

                        break;
                    default:
                        $data = json_decode(stripslashes(array_get($header, 'data', '{}')), true);
                        $data = (is_null($data) || !is_array($data)) ? [] : $data;
                        $namespace = array_get($header, 'namespace');
                        $name = array_get($header, 'name');
                        $mustUnderstand = array_get($header, 'mustunderstand', false);
                        $actor = array_get($header, 'actor');

                        if (!empty($namespace) && !empty($name) && !empty($data)) {
                            $soapHeaders[] = new \SoapHeader($namespace, $name, $data, $mustUnderstand, $actor);
                        }
                }
            }

            if (!empty($soapHeaders)) {
                $this->client->__setSoapHeaders($soapHeaders);
            }
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Unexpected SOAP Service Exception:\n{$ex->getMessage()}");
        }
    }

    /**
     * A chance to pre-process the data.
     *
     * @return mixed|void
     */
    protected function preProcess()
    {
        parent::preProcess();

        $this->checkPermission($this->getRequestedAction(), $this->name);
    }

    /**
     * {@inheritdoc}
     */
    public function getResources($only_handlers = false)
    {
        if ($only_handlers) {
            return [];
        }

        $refresh = $this->request->getParameterAsBool(ApiOptions::REFRESH);
        $result = $this->getFunctions($refresh);
        $resources = [];
        foreach ($result as $function) {
            $access = $this->getPermissions($function->name);
            if (!empty($access)) {
                $out = $function->toArray();
                $out['access'] = VerbsMask::maskToArray($access);
                $resources[] = $out;
            }
        }

        return $resources;
    }

    /**
     * @param bool $refresh
     *
     * @return FunctionSchema[]
     */
    public function getFunctions($refresh = false)
    {
        if ($refresh ||
            (empty($this->functions) &&
                (null === $this->functions = $this->getFromCache('functions')))
        ) {
            $functions = $this->client->__getFunctions();
            $structures = $this->getTypes($refresh);
            $names = [];
            foreach ($functions as $function) {
                $schema = new FunctionSchema($function);
                $schema->requestFields =
                    isset($structures[$schema->requestType]) ? $structures[$schema->requestType] : null;
                $schema->responseFields =
                    isset($structures[$schema->responseType]) ? $structures[$schema->responseType] : null;
                $names[strtolower($schema->name)] = $schema;
            }
            ksort($names);
            $this->functions = $names;
            $this->addToCache('functions', $this->functions, true);
        }

        return $this->functions;
    }

    public function parseWsdlStructure($structure)
    {
    }

    /**
     * @param bool $refresh
     *
     * @return FunctionSchema[]
     */
    public function getTypes($refresh = false)
    {
        if ($refresh ||
            (empty($this->types) &&
                (null === $this->types = $this->getFromCache('types')))
        ) {
            $types = $this->client->__getTypes();
            $structures = [];
            foreach ($types as $type) {
                if (0 === substr_compare($type, 'struct ', 0, 7)) {
                    // declared as "struct type { data_type field; ...}
                    $type = substr($type, 7);
                    $name = strstr($type, ' ', true);
                    $body = trim(strstr($type, ' '), "{} \t\n\r\0\x0B");
                    $parameters = [];
                    foreach (explode(';', $body) as $param) {
                        // declared as "type data_type"
                        $parts = explode(' ', trim($param));
                        if (count($parts) > 1) {
                            $parameters[trim($parts[1])] = trim($parts[0]);
                        }
                    }
                    $structures[$name] = $parameters;
                } else {
                    // declared as "type data_type"
                    $parts = explode(' ', $type);
                    if (count($parts) > 1) {
                        $structures[$parts[0]] = $parts[1];
                    }
                }
            }
            ksort($structures);
            $this->types = $structures;
            $this->addToCache('types', $this->types, true);
        }

        return $this->types;
    }

    /**
     *
     */
    public function refreshTableCache()
    {
        $this->removeFromCache('functions');
        $this->functions = [];
        $this->removeFromCache('types');
        $this->types = [];
    }

    /**
     * @param string $name       The name of the function to check
     * @param bool   $returnName If true, the function name is returned instead of TRUE
     *
     * @throws \InvalidArgumentException
     * @return bool|string
     */
    public function doesFunctionExist($name, $returnName = false)
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('Function name cannot be empty.');
        }

        //  Build the lower-cased table array
        $functions = $this->getFunctions(false);

        //	Search normal, return real name
        $ndx = strtolower($name);
        if (isset($functions[$ndx])) {
            return $returnName ? $functions[$ndx]->name : true;
        }

        return false;
    }

    /**
     * @param $function
     * @param $payload
     *
     * @return mixed
     * @throws \DreamFactory\Core\Exceptions\NotFoundException
     */
    protected function callFunction($function, $payload)
    {
        if (false === ($function = $this->doesFunctionExist($function, true))) {
            throw new NotFoundException("Function '$function' does not exist on this service.");
        }

        $result = $this->client->$function($payload);

        $result = static::object2Array($result);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function handleGet()
    {
        if (empty($this->resource)) {
            return parent::handleGET();
        }

        $result = $this->callFunction($this->resource, $this->request->getParameters());

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $idField = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
        $result = ResourcesWrapper::cleanResources($result, $asList, $idField, ApiOptions::FIELDS_ALL, !empty($meta));

        return $result;
    }

    /**
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     * @throws \DreamFactory\Core\Exceptions\NotFoundException
     * @throws \DreamFactory\Core\Exceptions\RestException
     */
    protected function handlePost()
    {
        if (empty($this->resource)) {
            // not currently supported, maybe batch opportunity?
            return false;
        }

        $result = $this->callFunction($this->resource, $this->request->getPayloadData());

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $idField = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
        $result = ResourcesWrapper::cleanResources($result, $asList, $idField, ApiOptions::FIELDS_ALL, !empty($meta));

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getApiDocInfo()
    {
        $name = strtolower($this->name);
        $capitalized = Inflector::camelize($this->name);
        $base = parent::getApiDocInfo();

        $apis = [];

        foreach ($this->getFunctions() as $resource) {

            $access = $this->getPermissions($resource->name);
            if (!empty($access)) {

                $apis['/' . $name . '/' . $resource->name] = [
                    'post' => [
                        'tags'              => [$name],
                        'operationId'       => 'call' . $capitalized . $resource->name,
                        'summary'           => 'call' . $capitalized . $resource->name . '()',
                        'description'       => $resource->description,
                        'x-publishedEvents' => [
                            $name . '.' . $resource->name . '.call',
                            $name . '.function_called',
                        ],
                        'parameters'        => [
                            [
                                'name'        => 'body',
                                'description' => 'Data containing name-value pairs of fields to send.',
                                'schema'      => ['$ref' => '#/definitions/' . $resource->requestType],
                                'in'          => 'body',
                                'required'    => true,
                            ],
                        ],
                        'responses'         => [
                            '200'     => [
                                'description' => 'Success',
                                'schema'      => ['$ref' => '#/definitions/' . $resource->responseType]
                            ],
                            'default' => [
                                'description' => 'Error',
                                'schema'      => ['$ref' => '#/definitions/Error']
                            ]
                        ],
                    ],
                ];
            }
        }

        $models = [];
        foreach ($this->getTypes() as $name => $parameters) {
            if (!isset($models[$name])) {
                $properties = [];
                if (is_array($parameters)) {
                    foreach ($parameters as $field => $type) {
                        $properties[$field] = ['type' => $type, 'description' => ''];
                    }
                    $models[$name] = ['type' => $name, 'properties' => $properties];
                }
            }
        }

        $base['paths'] = array_merge($base['paths'], $apis);
        $base['definitions'] = array_merge($base['definitions'], $models);

        return $base;
    }

    /**
     * @param $object
     *
     * @return array
     */
    protected static function object2Array($object)
    {
        if (is_object($object)) {
            return array_map([static::class, __FUNCTION__], get_object_vars($object));
        } else if (is_array($object)) {
            return array_map([static::class, __FUNCTION__], $object);
        } else {
            return $object;
        }
    }
}