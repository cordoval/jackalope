<?php
namespace Jackalope\Transport\Jackrabbit;

use PHPCR\PropertyType;
use Jackalope\Transport\curl;
use Jackalope\TransactionalTransportInterface;
use Jackalope\NotImplementedException;
use DOMDocument;
use Jackalope\NodeType\NodeTypeManager;

/**
 * Connection to one Jackrabbit server.
 *
 * This class handles the communication between Jackalope and Jackrabbit over
 * Davex. Once the login method has been called, the workspace is set and can not be
 * changed anymore.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0, January 2004
 */
class Client implements TransactionalTransportInterface
{
    /**
     * Description of the namspace to be used for communication with the server.
     * @var string
     */
    const NS_DCR = 'http://www.day.com/jcr/webdav/1.0';

    /**
     * Identifier of the used namespace.
     * @var string
     */
    const NS_DAV = 'DAV:';

    /**
     * Representation of a XML string header.
     *
     * @todo TODO: seems not to be used anymore.
     *
     * @var string
     */
    const REGISTERED_NAMESPACES =
        '<?xml version="1.0" encoding="UTF-8"?>< xmlns:dcr="http://www.day.com/jcr/webdav/1.0"/>';

    /**
     * The factory to instantiate objects
     * @var Factory
     */
    protected $factory;

    /**
     * Server url including protocol.
     *
     * i.e http://localhost:8080/server/
     * constructor ensures the trailing slash /
     *
     * @var string
     */
    protected $server;

    /**
     * Workspace name the transport is bound to
     * @var string
     */
    protected $workspace;

    /**
     * Identifier of the workspace including the used protocol and server name.
     *
     * "$server/$workspace" without trailing slash
     *
     *  @var string
     */
    protected $workspaceUri;

    /**
     * Root node path with server domain without trailing slash.
     *
     * "$server/$workspace/jcr%3aroot
     * (make sure you never hardcode the jcr%3aroot, its ugly)
     * @todo TODO: apparently, jackrabbit handles the root node by name - it is invisible everywhere for the api,
     *             but needed when talking to the backend... could that name change?
     *
     * @var string
     */
    protected $workspaceUriRoot;

    /**
     * Set of credentials necessary to connect to the server or else.
     * @var \PHPCR\CredentialsInterface
     */
    protected $credentials;

    /**
     * The cURL resource handle
     * @var curl
     */
    protected $curl = null;

    /**
     *  A list of additional HTTP headers to be sent on each request
     *  @var array[]string
     */

    protected $defaultHeaders = array();

    /**
     *  @var bool Send Expect: 100-continue header
     */

    protected $sendExpect = false;

    /**
     * @var \Jackalope\NodeType\NodeTypeXmlConverter
     */
    protected $typeXmlConverter;

    /**
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * Check if an initial PROPFIND should be send to check if repository exists
     * This is according to the JCR specifications and set to true by default
     * @see setCheckLoginOnServer
     * @var bool
     */
    protected $checkLoginOnServer = true;

    /**
      * The transaction token received by a LOCKing request
      *
      * Is FALSE while no transaction running.
      * @var string|FALSE
      */
    protected $transactionToken = false;

    /**
     * Create a transport pointing to a server url.
     *
     * @param object $factory  an object factory implementing "get" as described in \Jackalope\Factory.
     * @param serverUri location of the server
     */
    public function __construct($factory, $serverUri)
    {
        $this->factory = $factory;
        // append a slash if not there
        if ('/' !== substr($serverUri, -1)) {
            $serverUri .= '/';
        }
        $this->server = $serverUri;
    }

    /**
     * Tidies up the current cUrl connection.
     */
    public function __destruct()
    {
        if ($this->curl) {
            $this->curl->close();
        }
    }

    /**
     * Add a HTTP header which is sent on each Request
     *
     * This is a Jackrabbit Davex specific option.
     */
    public function addDefaultHeader($header)
    {
        $this->defaultHeaders[] = $header;
    }

    /**
     * If you want to send the "Expect: 100-continue" header on larger
     * PUT and POST requests, set this to true.
     *
     * Disabled by default.
     *
     * This is a Jackrabbit Davex specific option.
     *
     * @param bool $send
     */
    public function sendExpect($send = true)
    {
        $this->sendExpect = $send;
    }

    /**
     * Makes sure there is an open curl connection.
     *
     * @return Jackalope\Transport\Jackrabbit\Request The Request
     */
    protected function getRequest($method, $uri)
    {
        if (!is_array($uri)) {
            $uri = array($uri => $uri);
        }

        if (is_null($this->curl)) {
            // lazy init curl
            $this->curl = new curl();
        } elseif ($this->curl === false) {
            // but do not re-connect, rather report the error if trying to access a closed connection
            throw new \LogicException("Tried to start a request on a closed transport ($method for ".var_export($uri,true).")");
        }

        foreach ($uri as $key => $row) {
            $uri[$key] = $this->addWorkspacePathToUri($row);
        }


        $request = $this->factory->get('Transport\Jackrabbit\Request', array($this->curl, $method, $uri));
        $request->setCredentials($this->credentials);
        foreach ($this->defaultHeaders as $header) {
            $request->addHeader($header);
        }

        if (!$this->sendExpect) {
            $request->addHeader("Expect:");
        }

        return $request;
    }

    // inherit all doc
    public function login(\PHPCR\CredentialsInterface $credentials, $workspaceName)
    {
        if ($this->credentials) {
            throw new \PHPCR\RepositoryException(
                'Do not call login twice. Rather instantiate a new Transport object '.
                'to log in as different user or for a different workspace.'
            );
        }
        if (!$credentials instanceof \PHPCR\SimpleCredentials) {
            throw new \PHPCR\LoginException('Unkown Credentials Type: '.get_class($credentials));
        }

        $this->credentials = $credentials;
        $this->workspace = $workspaceName;
        $this->workspaceUri = $this->server . $workspaceName;
        $this->workspaceUriRoot = $this->workspaceUri . "/jcr:root";

        if (!$this->checkLoginOnServer ) {
            return true;
        }

        $request = $this->getRequest(Request::PROPFIND, $this->workspaceUri);
        $request->setBody($this->buildPropfindRequest(array('D:workspace', 'dcr:workspaceName')));
        $dom = $request->executeDom();

        $set = $dom->getElementsByTagNameNS(self::NS_DCR, 'workspaceName');
        if ($set->length != 1) {
            throw new \PHPCR\RepositoryException('Unexpected answer from server: '.$dom->saveXML());
        }

        if ($set->item(0)->textContent != $this->workspace) {
            throw new \PHPCR\RepositoryException('Wrong workspace in answer from server: '.$dom->saveXML());
        }
        return true;
    }

    // inherit all doc
    public function logout()
    {
        if (!empty($this->curl)) {
            $this->curl->close();
        }
        $this->curl = false;
    }

    // inherit all doc
    public function setCheckLoginOnServer($bool)
    {
        $this->checkLoginOnServer = $bool;
    }

    // inherit all doc
    public function getRepositoryDescriptors()
    {
        $request = $this->getRequest(Request::REPORT, $this->server);
        $request->setBody($this->buildReportRequest('dcr:repositorydescriptors'));
        $dom = $request->executeDom();

        if ($dom->firstChild->localName != 'repositorydescriptors-report'
            || $dom->firstChild->namespaceURI != self::NS_DCR
        ) {
            throw new \PHPCR\RepositoryException('Error talking to the backend. '.$dom->saveXML());
        }

        $descs = $dom->getElementsByTagNameNS(self::NS_DCR, 'descriptor');
        $descriptors = array();
        foreach ($descs as $desc) {
            $values = array();
            foreach ($desc->getElementsByTagNameNS(self::NS_DCR, 'descriptorvalue') as $value) {
                $values[] = $value->textContent;
            }
            if ($desc->childNodes->length == 2) {
                //there was one type and one value => this is a single value property
                //TODO: is this the correct assumption? or should the backend tell us specifically?
                $descriptors[$desc->firstChild->textContent] = $values[0];
            } else {
                $descriptors[$desc->firstChild->textContent] = $values;
            }
        }
        return $descriptors;
    }

    // inherit all doc
    public function createWorkspace($name, $srcWorkspace = null)
    {
        throw new \Jackalope\NotImplementedException();
    }

    // inherit all doc
    public function getAccessibleWorkspaceNames()
    {
        $request = $this->getRequest(Request::PROPFIND, $this->server);
        $request->setBody($this->buildPropfindRequest(array('D:workspace')));
        $request->setDepth(1);
        $dom = $request->executeDom();

        $workspaces = array();
        foreach ($dom->getElementsByTagNameNS(self::NS_DAV, 'workspace') as $value) {
            if (!empty($value->nodeValue)) {
                $workspaces[] = substr(trim($value->nodeValue), strlen($this->server), -1);
            }
        }
        return array_unique($workspaces);
    }

    // inherit all doc
    public function getNode($path)
    {
        $path = $this->encodePathForDavex($path);
        $path .= '.0.json';

        $request = $this->getRequest(Request::GET, $path);
        $request->setTransactionId($this->transactionToken);
        try {
            return $request->executeJson();
        } catch (\PHPCR\PathNotFoundException $e) {
            throw new \PHPCR\ItemNotFoundException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // inherit all doc
    public function getNodes($paths)
    {
        if (count($paths) == 0) {
            return array();
        }

        if (count($paths) == 1) {
            $url = array_shift($paths);
            try {
                return array($url => $this->getNode($url));
            } catch (\PHPCR\ItemNotFoundException $e) {
                return array();
            }
        }
        $body = array();

        $url = $this->encodePathForDavex("/").".0.json";
        foreach ($paths as $path) {
            $body[] = http_build_query(array(":include"=>$path));
        }
        $body = implode("&",$body);
        //I actually would prefer POST, so leaving the code here, waiting for an answer from apache
        $request = $this->getRequest(Request::GET, $url.'?'.$body);
        //$request->setBody($body);
        //$request->setContentType('application/x-www-form-urlencoded');
        $request->setTransactionId($this->transactionToken);
        try {
            $data = $request->executeJson();
            return $data->nodes;
        } catch (\PHPCR\PathNotFoundException $e) {
            throw new \PHPCR\ItemNotFoundException($e->getMessage(), $e->getCode(), $e);
        } catch (\PHPCR\RepositoryException $e) {
            if ($e->getMessage() == 'HTTP 403: Prefix must not be empty (org.apache.jackrabbit.spi.commons.conversion.IllegalNameException)') {
                throw new \PHPCR\UnsupportedRepositoryOperationException("Jackalope currently needs a patched jackrabbit for Session->getNodes() to work. Until our patches make it into the official distribution, see https://github.com/jackalope/jackrabbit/blob/2.2-jackalope/README.jackalope.patches.md for details and downloads.");
            }
            throw $e;
        }
    }

    // inherit all doc
    public function getProperty($path)
    {
        throw new NotImplementedException();
        /*
         * TODO: implement
         * jackrabbit: instead of fetching the node, we could make Transport provide it with a
         * GET /server/tests/jcr%3aroot/tests_level1_access_base/multiValueProperty/jcr%3auuid
         * (davex getItem uses json, which is not applicable to properties)
         */
    }

    // inherit all doc
    public function getBinaryStream($path)
    {
        $path = $this->encodePathForDavex($path);
        $request = $this->getRequest(Request::GET, $path);
        $request->setTransactionId($this->transactionToken);
        $curl = $request->execute(true);
        switch($curl->getHeader('Content-Type')) {
            case 'text/xml; charset=utf-8':
                return $this->decodeBinaryDom($curl->getResponse());
            case 'jcr-value/binary; charset=utf-8':
                // TODO: OPTIMIZE stream handling!
                $stream = fopen('php://memory', 'rwb+');
                fwrite($stream, $curl->getResponse());
                rewind($stream);
                return $stream;
        }

        throw new \PHPCR\RepositoryException('Unknown encoding of binary data: '.$curl->getHeader('Content-Type'));
    }

    /**
     * parse the multivalue binary response (a list of base64 encoded values)
     *
     * <dcr:values xmlns:dcr="http://www.day.com/jcr/webdav/1.0">
     *   <dcr:value dcr:type="Binary">aDEuIENoYXB0ZXIgMSBUaXRsZQoKKiBmb28KKiBiYXIKKiogZm9vMgoqKiBmb28zCiogZm9vMAoKfHwgaGVhZGVyIHx8IGJhciB8fAp8IGggfCBqIHwKCntjb2RlfQpoZWxsbyB3b3JsZAp7Y29kZX0KCiMgZm9vCg==</dcr:value>
     *   <dcr:value dcr:type="Binary">aDEuIENoYXB0ZXIgMSBUaXRsZQoKKiBmb28KKiBiYXIKKiogZm9vMgoqKiBmb28zCiogZm9vMAoKfHwgaGVhZGVyIHx8IGJhciB8fAp8IGggfCBqIHwKCntjb2RlfQpoZWxsbyB3b3JsZAp7Y29kZX0KCiMgZm9vCg==</dcr:value>
     * </dcr:values>
     *
     * @param string $xml the xml as returned by jackrabbit
     *
     * @return array of stream resources
     *
     * @throws \PHPCR\RepositoryException if the xml is invalid or any value is not of type binary
     */
    private function decodeBinaryDom($xml)
    {
        $dom = new \DOMDocument();
        if (! $dom->loadXML($xml)) {
            throw new \PHPCR\RepositoryException("Failed to load xml data:\n\n$xml");
        }
        $ret = array();
        foreach ($dom->getElementsByTagNameNS(self::NS_DCR, 'values') as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DCR, 'value') as $value) {
                if ($value->getAttributeNS(self::NS_DCR, 'type') != \PHPCR\PropertyType::TYPENAME_BINARY) {
                    throw new \PHPCR\RepositoryException('Expected binary value but got '.$value->getAttributeNS(self::NS_DCR, 'type'));
                }
                // TODO: OPTIMIZE stream handling!
                $stream = fopen('php://memory', 'rwb+');
                fwrite($stream, base64_decode($value->textContent));
                rewind($stream);
                $ret[] = $stream;
            }
        }
        return $ret;
    }

    // inherit all doc
    public function getReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name);
    }

    // inherit all doc
    public function getWeakReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, true);
    }

    // inherit all doc
    protected function getNodeReferences($path, $name = null, $weak_reference = false)
    {
        $path = $this->encodePathForDavex($path);
        $identifier = $weak_reference ? 'weakreferences' : 'references';
        $request = $this->getRequest(Request::PROPFIND, $path);
        $request->setTransactionId($this->transactionToken);
        $request->setBody($this->buildPropfindRequest(array('dcr:'.$identifier)));
        $request->setDepth(0);
        $dom = $request->executeDom();

        $references = array();

        foreach ($dom->getElementsByTagNameNS(self::NS_DCR, $identifier) as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DAV, 'href') as $ref) {
                $refpath = str_replace($this->workspaceUriRoot, '',  urldecode($ref->textContent));
                if ($name === null || basename($refpath) === $name) {
                    $references[] = str_replace($this->workspaceUriRoot, '',  urldecode($ref->textContent));
                }
            }
        }

        return $references;
    }

    // inherit all doc
    public function checkinItem($path)
    {
        $path = $this->encodePathForDavex($path);
        try {
            $request = $this->getRequest(Request::CHECKIN, $path);
            $request->setTransactionId($this->transactionToken);
            $curl = $request->execute(true);
            if ($curl->getHeader("Location")) {
                return $this->stripServerRootFromUri(urldecode($curl->getHeader("Location")));
            }
        } catch (HTTPErrorException $e) {
            if ($e->getCode() == 405) {
                throw new \PHPCR\UnsupportedRepositoryOperationException();
            }
            throw new \PHPCR\RepositoryException($e->getMessage());
        }

        // TODO: not sure what this means
        throw new \PHPCR\RepositoryException();
    }

    // inherit all doc
    public function checkoutItem($path)
    {
        $path = $this->encodePathForDavex($path);
        try {
            $request = $this->getRequest(Request::CHECKOUT, $path);
            $request->setTransactionId($this->transactionToken);
            $request->execute();
        } catch (HTTPErrorException $e) {
            if ($e->getCode() == 405) {
                // TODO: when checking out a non-versionable node, we get here too. in that case the exception is very wrong
                throw new \PHPCR\UnsupportedRepositoryOperationException($e->getMessage());
            }
            throw new \PHPCR\RepositoryException($e->getMessage());
        }
        return;
    }

    // inherit all doc
    public function restoreItem($removeExisting, $versionPath, $path)
    {
        $path = $this->encodePathForDavex($path);

        $body ='<D:update xmlns:D="DAV:">
	<D:version>
		<D:href>'.$this->addWorkspacePathToUri($versionPath).'</D:href>
	</D:version>';
        if ($removeExisting) {
            $body .= '<dcr:removeexisting xmlns:dcr="http://www.day.com/jcr/webdav/1.0" />';
        }
        $body .= '</D:update>';

        $request = $this->getRequest(Request::UPDATE, $path);
        $request->setBody($body);
        $request->setTransactionId($this->transactionToken);
        $request->execute(); // errors are checked in request
    }

    // inherit all doc
    public function getVersionHistory($path)
    {
        $path = $this->encodePathForDavex($path);
        $request = $this->getRequest(Request::GET, $path."/jcr:versionHistory");
        $request->setTransactionId($this->transactionToken);
        $resp = $request->execute();
        return $resp;
    }

    // inherit all doc
    public function query(\PHPCR\Query\QueryInterface $query)
    {
        if ($query instanceof \Jackalope\Query\SqlQuery
            || $query instanceof \PHPCR\Query\QOM\QueryObjectModelInterface
        ) {
            $querystring = $query->getStatementSql2();
        } else {
            throw new \PHPCR\UnsupportedRepositoryOperationException('Unknown query type: '.$query->getLanguage());
        }
        $limit = $query->getLimit();
        $offset = $query->getOffset();

        $body ='<D:searchrequest xmlns:D="DAV:"><JCR-SQL2><![CDATA['.$querystring.']]></JCR-SQL2>';

        if (null !== $limit || null !== $limit) {
            $body .= '<D:limit>';
            if (null !== $limit) {
                $body .= '<D:nresults>'.(int)$limit.'</D:nresults>';
            }
            if (null !== $offset) {
                $body .= '<offset>'.(int)$offset.'</offset>';
            }
            $body .= '</D:limit>';
        }

        $body .= '</D:searchrequest>';

        $path = $this->addWorkspacePathToUri('/');
        $request = $this->getRequest(Request::SEARCH, $path);
        $request->setTransactionId($this->transactionToken);
        $request->setBody($body);

        $rawData = $request->execute();

        $dom = new \DOMDocument();
        $dom->loadXML($rawData);

        $rows = array();
        foreach ($dom->getElementsByTagName('response') as $row) {
            $columns = array();
            foreach ($row->getElementsByTagName('column') as $column) {
                $sets = array();
                foreach ($column->childNodes as $childNode) {
                    $sets[$childNode->tagName] = $childNode->nodeValue;
                }

                // TODO this can happen inside joins
                // probabably caused by https://issues.apache.org/jira/browse/JCR-3089
                if (!isset($sets['dcr:value'])) {
                    continue;
                }

                // TODO if this bug is fixed, spaces may be urlencoded instead of the escape sequence: https://issues.apache.org/jira/browse/JCR-2997
                // the following line fails for nodes with "_x0020 " in their name, changing that part to " x0020_"
                // other characters like < and > are urlencoded, which seems to be handled by dom already.
                $sets['dcr:value'] = str_replace('_x0020_', ' ', $sets['dcr:value']);

                $columns[] = $sets;
            }

            $rows[] = $columns;
        }

        return $rows;
    }

    // inherit all doc
    public function deleteNode($path)
    {
        $path = $this->encodePathForDavex($path);

        $request = $this->getRequest(Request::DELETE, $path);
        $request->setTransactionId($this->transactionToken);
        $request->execute();
        return true;
    }

    // inherit all doc
    public function deleteProperty($path)
    {
        return $this->deleteNode($path);
    }

    // inherit all doc
    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        $srcAbsPath = $this->encodePathForDavex($srcAbsPath);
        $dstAbsPath = $this->encodePathForDavex($dstAbsPath);

        if ($srcWorkspace) {
            $srcAbsPath = $this->server . $srcAbsPath;
        }

        $request = $this->getRequest(Request::COPY, $srcAbsPath);
        $request->setDepth(Request::INFINITY);
        $request->addHeader('Destination: '.$this->addWorkspacePathToUri($dstAbsPath));
        $request->setTransactionId($this->transactionToken);
        $request->execute();
    }

    // inherit all doc
    public function moveNode($srcAbsPath, $dstAbsPath)
    {
        $srcAbsPath = $this->encodePathForDavex($srcAbsPath);
        $dstAbsPath = $this->encodePathForDavex($dstAbsPath);

        $request = $this->getRequest(Request::MOVE, $srcAbsPath);
        $request->setDepth(Request::INFINITY);
        $request->addHeader('Destination: '.$this->addWorkspacePathToUri($dstAbsPath));
        $request->setTransactionId($this->transactionToken);
        $request->execute();
    }

    // inherit all doc
    public function cloneFrom($srcWorkspace, $srcAbsPath, $destAbsPath, $removeExisting)
    {
        throw new NotImplementedException();
    }

    // inherit all doc
    public function storeNode(\PHPCR\NodeInterface $node)
    {
        $path = $node->getPath();
        $path = $this->encodePathForDavex($path);

        $buffer = array();
        $body = '<?xml version="1.0" encoding="UTF-8"?>';
        $body .= $this->createNodeMarkup($path, $node->getProperties(), $node->getNodes(), $buffer);

        $request = $this->getRequest(Request::MKCOL, $path);
        $request->setBody($body);
        $request->setTransactionId($this->transactionToken);
        try {
            $request->execute();
        } catch(HTTPErrorException $e) {
            // TODO: this will need to be changed when we refactor transport to use the diff format to store changes.
            if (strpos($e->getMessage(), "405") !== false && strpos($e->getMessage(), "MKCOL") !== false) {
                // TODO: can the 405 exception be thrown for other reasons too?
                throw new \PHPCR\ItemExistsException('This node probably already exists: '.$node->getPath(), $e->getCode(), $e);
            }
            // TODO: can we throw any other more specific errors here?
            throw new \PHPCR\RepositoryException('Something went wrong while saving node: '.$node->getPath(), $e->getCode(), $e);
        }

        // store single-valued multivalue properties separately
        foreach ($buffer as $path => $body) {
            $request = $this->getRequest(Request::PUT, $path);
            $request->setBody($body);
            $request->setTransactionId($this->transactionToken);
            $request->execute();
        }

        return true;
    }

    /**
     * create the node markup and a list of value dispatches for multivalue properties
     *
     * this is a recursive function.
     *
     * @param string $path path to the current node, basename is the name of the node
     * @param array $properties of this node
     * @param array $children nodes of this node
     * @param array $buffer list of xml strings to set multivalue properties
     */
    protected function createNodeMarkup($path, $properties, $children, array &$buffer)
    {
        $body = '<sv:node xmlns:sv="http://www.jcp.org/jcr/sv/1.0" xmlns:nt="http://www.jcp.org/jcr/nt/1.0" sv:name="'.basename($path).'">';

        foreach ($properties as $name => $property) {
            $type = \PHPCR\PropertyType::nameFromValue($property->getType());
            $nativeValue = $property->getValueForStorage();
            $valueBody = '';
            // handle multivalue properties
            if (is_array($nativeValue)) {
                // multivalue properties with many rows can be inlined
                if (count($nativeValue) > 1 || $name === 'jcr:mixinTypes') {
                    foreach ($nativeValue as $value) {
                        $valueBody .= '<sv:value>'.$this->propertyToXmlString($value, $type).'</sv:value>';
                    }
                } else {
                    // multivalue properties with just one value have to be saved separately to transmit the multivalue info
                    $buffer[$path.'/'.$name] = '<?xml version="1.0" encoding="UTF-8"?><dcr:values xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.
                        '<dcr:value dcr:type="'.$type.'">'.$this->propertyToXmlString(reset($nativeValue), $type).'</dcr:value>'.
                    '</dcr:values>';
                    continue;
                }
            } else {
                // handle single value properties
                $valueBody = '<sv:value>'.$this->propertyToXmlString($nativeValue, $type).'</sv:value>';
            }
            $body .= '<sv:property sv:name="'.$name.'" sv:type="'.$type.'">'.$valueBody.'</sv:property>';
        }

        foreach ($children as $name => $node) {
            $body .= $this->createNodeMarkup($path.'/'.$name, $node->getProperties(), $node->getNodes(), $buffer);
        }

        return $body . '</sv:node>';
    }

    // inherit all doc
    public function storeProperty(\PHPCR\PropertyInterface $property)
    {
        $path = $property->getPath();
        $path = $this->encodePathForDavex($path);

        $typeid = $property->getType();
        $type = PropertyType::nameFromValue($typeid);
        $nativeValue = $property->getValueForStorage();

        $request = $this->getRequest(Request::PUT, $path);
        if ($property->getName() === 'jcr:mixinTypes') {
            $uri = $this->addWorkspacePathToUri(dirname($path) === '\\' ? '/' : dirname($path));
            $request->setUri($uri);
            $request->setMethod(Request::PROPPATCH);
            $body = '<?xml version="1.0" encoding="UTF-8"?>'.
                '<D:propertyupdate xmlns:D="DAV:">'.
                '<D:set>'.
                '<D:prop>'.
                '<dcr:mixinnodetypes xmlns:dcr="http://www.day.com/jcr/webdav/1.0">';
            foreach ($nativeValue as $value) {
                $body .= '<dcr:nodetype><dcr:nodetypename>'.$value.'</dcr:nodetypename></dcr:nodetype>';
            }
            $body .= '</dcr:mixinnodetypes>'.
                '</D:prop>'.
                '</D:set>'.
                '</D:propertyupdate>';
        } elseif (is_array($nativeValue)) {
            $body = '<?xml version="1.0" encoding="UTF-8"?>'.
                '<jcr:values xmlns:jcr="http://www.day.com/jcr/webdav/1.0">';
            foreach ($nativeValue as $value) {
                $body .= '<jcr:value jcr:type="'.$type.'">'.$this->propertyToXmlString($value, $type).'</jcr:value>';
            }
            $body .= '</jcr:values>';
        } else {
            $body = $this->propertyToRawString($nativeValue, $type);
            $request->setContentType('jcr-value/'.strtolower($type));
        }
        $request->setBody($body);
        $request->setTransactionId($this->transactionToken);
        $request->execute();

        return true;
    }

    /**
     * This method is used when building an XML of the properties
     *
     * @param $value
     * @param $type
     * @return mixed|string
     */
    protected function propertyToXmlString($value, $type)
    {
        switch ($type) {
            case \PHPCR\PropertyType::TYPENAME_BOOLEAN:
                return $value ? 'true' : 'false';
            case \PHPCR\PropertyType::TYPENAME_DATE:
                return PropertyType::convertType($value, PropertyType::STRING);
            case \PHPCR\PropertyType::TYPENAME_BINARY:
                return base64_encode(stream_get_contents($value));
            case \PHPCR\PropertyType::TYPENAME_UNDEFINED:
            case \PHPCR\PropertyType::TYPENAME_STRING:
            case \PHPCR\PropertyType::TYPENAME_URI:
                $value = str_replace(']]>',']]]]><![CDATA[>',$value);
                return '<![CDATA['.$value.']]>';
        }
        return $value;
    }

    /**
     * This method is used to directly set a property
     *
     * @param $value
     * @param $type
     * @return mixed|string
     */
    protected function propertyToRawString($value, $type)
    {
        switch ($type) {
            case \PHPCR\PropertyType::TYPENAME_BINARY:
                $ret = stream_get_contents($value);
                fclose($value);
                return $ret;
            case \PHPCR\PropertyType::TYPENAME_UNDEFINED:
            case \PHPCR\PropertyType::TYPENAME_STRING:
            case \PHPCR\PropertyType::TYPENAME_URI:
                return $value;
        }
        return $this->propertyToXmlString($value, $type);
    }

    // inherit all doc
    public function getNodePathForIdentifier($uuid)
    {
        $request = $this->getRequest(Request::REPORT, $this->workspaceUri);
        $request->setBody($this->buildLocateRequest($uuid));
        $request->setTransactionId($this->transactionToken);
        $dom = $request->executeDom();

        /* answer looks like
           <D:multistatus xmlns:D="DAV:">
             <D:response>
                 <D:href>http://localhost:8080/server/tests/jcr%3aroot/tests_level1_access_base/idExample/</D:href>
             </D:response>
         </D:multistatus>
        */
        $set = $dom->getElementsByTagNameNS(self::NS_DAV, 'href');
        if ($set->length != 1) {
            throw new \PHPCR\RepositoryException('Unexpected answer from server: '.$dom->saveXML());
        }
        $fullPath = $set->item(0)->textContent;
        if (strncmp($this->workspaceUriRoot, $fullPath, strlen($this->workspaceUri))) {
            throw new \PHPCR\RepositoryException(
                "Server answered a path that is not in the current workspace: uuid=$uuid, path=$fullPath, workspace=".
                $this->workspaceUriRoot
            );
        }
        return $this->stripServerRootFromUri(substr(\urldecode($fullPath),0,-1));
    }

    // inherit all doc
    public function getNamespaces()
    {
        $request = $this->getRequest(Request::REPORT, $this->workspaceUri);
        $request->setBody($this->buildReportRequest('dcr:registerednamespaces'));
        $request->setTransactionId($this->transactionToken);
        $dom = $request->executeDom();

        if ($dom->firstChild->localName != 'registerednamespaces-report'
            || $dom->firstChild->namespaceURI != self::NS_DCR
        ) {
            throw new \PHPCR\RepositoryException('Error talking to the backend. '.$dom->saveXML());
        }

        $mappings = array();
        $namespaces = $dom->getElementsByTagNameNS(self::NS_DCR, 'namespace');
        foreach ($namespaces as $elem) {
            $mappings[$elem->firstChild->textContent] = $elem->lastChild->textContent;
        }
        return $mappings;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \PHPCR\UnsupportedRepositoryOperationException if trying to
     *      overwrite existing prefix to new uri, as jackrabbit can not do this
     */
    public function registerNamespace($prefix, $uri)
    {
        // seems jackrabbit always expects full list of namespaces
        $namespaces = $this->getNamespaces();

        // check if prefix is already mapped
        if (isset($namespaces[$prefix])) {
            if ($namespaces[$prefix] == $uri) {
                // nothing to do, we already have the mapping
                return;
            }
            // unregister old mapping
            throw new \PHPCR\UnsupportedRepositoryOperationException("Trying to set existing prefix $prefix from ".$namespaces[$prefix]." to different uri $uri, but unregistering namespace is not supported by jackrabbit backend. You can move the old namespace to a different prefix before adding this prefix to work around this issue.");
        }

        // if target uri already exists elsewhere, do not re-send or result is random
        /* weird: we can not unset this or we get the unregister not
         * supported exception. but we can send two mappings and
         * jackrabbit does the right guess what we want and moves the
         * namespace to the new prefix

        if (false !== $expref = array_search($uri, $namespaces)) {
            unset($namespaces[$expref]);
        }
        */

        $request = $this->getRequest(Request::PROPPATCH, $this->workspaceUri);
        $namespaces[$prefix] = $uri;
        $request->setBody($this->buildRegisterNamespaceRequest($namespaces));
        $request->setTransactionId($this->transactionToken);
        $request->execute();
        return true;
    }

    // inherit all doc
    public function unregisterNamespace($prefix)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException('Unregistering namespace not supported by jackrabbit backend');

        /*
         * TODO: could look a bit like the following if the backend would support it
        $request = $this->getRequest(Request::PROPPATCH, $this->workspaceUri);
        // seems jackrabbit always expects full list of namespaces
        $namespaces = $this->getNamespaces();
        unset($namespaces[$prefix]);
        $request->setBody($this->buildRegisterNamespaceRequest($namespaces));
        $request->setTransactionId($this->transactionToken);
        $request->execute();
        return true;
        */
    }

    // inherit all doc
    public function getNodeTypes($nodeTypes = array())
    {
        $request = $this->getRequest(Request::REPORT, $this->workspaceUriRoot);
        $request->setBody($this->buildNodeTypesRequest($nodeTypes));
        $request->setTransactionId($this->transactionToken);
        $dom = $request->executeDom();

        if ($dom->firstChild->localName != 'nodeTypes') {
            throw new \PHPCR\RepositoryException('Error talking to the backend. '.$dom->saveXML());
        }

        if ($this->typeXmlConverter === null) {
            $this->typeXmlConverter = $this->factory->get('NodeType\NodeTypeXmlConverter');
        }

        return $this->typeXmlConverter->getNodeTypesFromXml($dom);
    }

    // inherit all doc
    public function beginTransaction()
    {
        $request = $this->getRequest(Request::LOCK, $this->workspaceUriRoot);
        $request->setDepth('infinity');
        $request->setTransactionId($this->transactionToken);
        $request->setBody('<?xml version="1.0" encoding="utf-8"?>'.
            '<D:lockinfo xmlns:D="'.self::NS_DAV.'" xmlns:jcr="'.self::NS_DCR.'">'.
            ' <D:lockscope><jcr:local /></D:lockscope>'.
            ' <D:locktype><jcr:transaction /></D:locktype>'.
            '</D:lockinfo>');

        $dom = $request->executeDom();
        $hrefs = $dom->getElementsByTagNameNS(self::NS_DAV, 'href');

        if (!$hrefs->length) {
            throw new \PHPCR\RepositoryException('No transaction token received');
        }
        $this->transactionToken = $hrefs->item(0)->textContent;
        return $this->transactionToken;
    }

    // inherit all doc
    protected function endTransaction($tag)
    {
        if ($tag != 'commit' && $tag != 'rollback') {
            throw new \InvalidArgumentException('Expected \'commit\' or \'rollback\' as argument');
        }

        $request = $this->getRequest(Request::UNLOCK, $this->workspaceUriRoot);
        $request->setLockToken($this->transactionToken);
        $request->setBody('<?xml version="1.0" encoding="utf-8"?>'.
            '<jcr:transactioninfo xmlns:jcr="'.self::NS_DCR.'">'.
            ' <jcr:transactionstatus><jcr:'.$tag.' /></jcr:transactionstatus>'.
            '</jcr:transactioninfo>');

        $request->execute();
        $this->transactionToken = false;
    }

    // inherit all doc
    public function commitTransaction()
    {
        $this->endTransaction('commit');
    }

    // inherit all doc
    public function rollbackTransaction()
    {
        $this->endTransaction('rollback');
    }

    // inherit all doc
    public function setTransactionTimeout($seconds)
    {
        throw new NotImplementedException();
    }

    // inherit all doc
    public function registerNodeTypesCnd($cnd, $allowUpdate)
    {
        $request = $this->getRequest(Request::PROPPATCH, $this->workspaceUri);
        $request->setTransactionId($this->transactionToken);
        $request->setBody($this->buildRegisterNodeTypeRequest($cnd, $allowUpdate));
        $request->execute();
        return true;
    }

    // inherit all doc
    public function registerNodeTypes($types, $allowUpdate)
    {
        throw new NotImplementedException('TODO: convert node type definition to cnd format and call registerNodeTypesCnd');
        //see http://jackrabbit.apache.org/node-type-notation.html
    }

    // inherit all doc
    public function getPermissions($path)
    {
        // TODO: OPTIMIZE - once we have ACL this might be done without any server request
        $body = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<dcr:privileges xmlns:dcr="http://www.day.com/jcr/webdav/1.0">' .
                '<D:href xmlns:D="DAV:">'.$this->addWorkspacePathToUri($path).'</D:href>' .
                '</dcr:privileges>';

        $valid_permissions = array(
            \PHPCR\SessionInterface::ACTION_ADD_NODE,
            \PHPCR\SessionInterface::ACTION_READ,
            \PHPCR\SessionInterface::ACTION_REMOVE,
            \PHPCR\SessionInterface::ACTION_SET_PROPERTY);

        $result = array();

        $request = $this->getRequest(Request::REPORT, $this->workspaceUri);
        $request->setBody($body);
        $request->setTransactionId($this->transactionToken);
        $dom = $request->executeDom();

        foreach ($dom->getElementsByTagNameNS(self::NS_DAV, 'current-user-privilege-set') as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DAV, 'privilege') as $privilege) {
                foreach ($privilege->childNodes as $child) {
                    $permission = str_replace('dcr:', '', $child->tagName);
                    if (! in_array($permission, $valid_permissions)) {
                        throw new \PHPCR\RepositoryException("Invalid permission '$permission'");
                    }
                    $result[] = $permission;
                }
            }
        }

        return $result;
    }

    /**
     * Build the xml required to register node types
     *
     * @param string $cnd the node type definition
     * @return string XML with register request
     *
     * @author david at liip.ch
     */
    protected function buildRegisterNodeTypeRequest($cnd, $allowUpdate)
    {
        $cnd = '<dcr:cnd>'.str_replace(array('<','>'), array('&lt;','&gt;'), $cnd).'</dcr:cnd>';
        $cnd .= '<dcr:allowupdate>'.($allowUpdate ? 'true' : 'false').'</dcr:allowupdate>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?><D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><dcr:nodetypes-cnd xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.$cnd.'</dcr:nodetypes-cnd></D:prop></D:set></D:propertyupdate>';
    }

    /**
     * Build the xml to update the namespaces
     *
     * You need to repeat all existing node type plus add your new ones
     *
     * @param array $mappings hashmap of prefix => uri for all existing and new namespaces
     */
    protected function buildRegisterNamespaceRequest($mappings)
    {
        $ns = '';
        foreach ($mappings as $prefix => $uri) {
            $ns .= "<dcr:namespace><dcr:prefix>$prefix</dcr:prefix><dcr:uri>$uri</dcr:uri></dcr:namespace>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?><D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><dcr:namespaces xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.
                $ns .
                '</dcr:namespaces></D:prop></D:set></D:propertyupdate>';
    }

    /**
     * Returns the XML required to request nodetypes
     *
     * @param array $nodesType The list of nodetypes you want to request for.
     * @return string XML with the request information.
     */
    protected function buildNodeTypesRequest(array $nodeTypes)
    {
        $xml = '<?xml version="1.0" encoding="utf-8" ?>'.
            '<jcr:nodetypes xmlns:jcr="http://www.day.com/jcr/webdav/1.0">';
        if (empty($nodeTypes)) {
            $xml .= '<jcr:all-nodetypes/>';
        } else {
            foreach ($nodeTypes as $nodetype) {
                $xml .= '<jcr:nodetype><jcr:nodetypename>'.$nodetype.'</jcr:nodetypename></jcr:nodetype>';
            }
        }
        $xml .='</jcr:nodetypes>';
        return $xml;
    }

    /**
     * Build PROPFIND request XML for the specified property names
     *
     * @param array $properties names of the properties to search for
     * @return string XML to post in the body
     */
    protected function buildPropfindRequest($properties)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.
            '<D:propfind xmlns:D="DAV:" xmlns:dcr="http://www.day.com/jcr/webdav/1.0"><D:prop>';
        if (!is_array($properties)) {
            $properties = array($properties);
        }
        foreach ($properties as $property) {
            $xml .= '<'. $property . '/>';
        }
        $xml .= '</D:prop></D:propfind>';
        return $xml;
    }

    /**
     * Build a REPORT XML request string
     *
     * @param string $name Name of the resource to be requested.
     * @return string XML string representing the head of the request.
     */
    protected function buildReportRequest($name)
    {
        return '<?xml version="1.0" encoding="UTF-8"?><' .
                $name .
               ' xmlns:dcr="http://www.day.com/jcr/webdav/1.0"/>';
    }

    /**
     * Build REPORT XML request for locating a node path by uuid
     *
     * @param string $uuid Unique identifier of the node to be asked for.
     * @return string XML sring representing the content of the request.
     */
    protected function buildLocateRequest($uuid)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<dcr:locate-by-uuid xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.
               '<D:href xmlns:D="DAV:">' .
                $uuid .
               '</D:href></dcr:locate-by-uuid>';
    }

    // inherit all doc
    public function setNodeTypeManager($nodeTypeManager)
    {
        $this->nodeTypeManager = $nodeTypeManager;
    }

    /**
     * Whether the path conforms to the specification and is supported by this implementation
     *
     * @see http://www.day.com/specs/jcr/2.0/3_Repository_Model.html#3.2.2%20Local%20Names
     *
     * TODO: the spec is extremly open and recommends to restrict further. We
     * currently have rather random restrictions
     *
     * @param string $path The path to validate
     *
     * @return boolean always true, exception if this is not a valid path
     *
     * @throws RepositoryException if the path contains invalid characters
     */
    protected function ensureValidPath($path)
    {
        if (! (strpos($path, '//') === false
              && strpos($path, '/../') === false
              && preg_match('/^[\w{}\/#:^+~*\[\]\. <>"\'-]*$/i', $path))
        ) {
            throw new \PHPCR\RepositoryException('Path is not well-formed or contains invalid characters: ' . $path);
        }
        // if we allow MORE stuff, we might have to adapt encodePathForDavex for escaping
    }

    /**
     * Checks if the path is absolute and valid, and properly urlencodes special characters
     *
     * This is to be used in the Davex headers. The XML requests can cope with unencoded stuff
     *
     * @param string $path to check
     *
     * @return string the cleaned path
     *
     * @throws \PHPCR\RepositoryException If path is not absolute or invalid
     */
    protected function encodePathForDavex($path)
    {
        if ('/' != substr($path, 0, 1)) {
            //sanity check
            throw new \PHPCR\RepositoryException("Implementation error: '$path' is not an absolute path");
        }
        $this->ensureValidPath($path);
        return str_replace(' ', '%20', $path); // TODO: does ensureValidPath allow other characters that should be encoded?
    }

    /**
     * remove the server and workspace part from an uri, leaving the absolute
     * path inside the current workspace
     *
     * @param string $uri a full uri including the server path, workspace and jcr%3aroot
     *
     * @return string absolute path in the current work space
     */
    protected function stripServerRootFromUri($uri)
    {
        return substr($uri,strlen($this->workspaceUriRoot));
    }

    /**
     * Prepends the workspace root to the uris that contain an absolute path
     *
     * @param string $uri The absolute path in the current workspace or server uri
     * @return string The server uri with this path
     * @throws \PHPCR\RepositoryException   If workspaceUri is missing (not logged in)
     */
    protected function addWorkspacePathToUri($uri)
    {
        if (substr($uri, 0, 1) === '/') {
            if (empty($this->workspaceUri)) {
                throw new \PHPCR\RepositoryException("Implementation error: Please login before accessing content");
            }
            $uri = $this->workspaceUriRoot . $uri;
        }
        return $uri;
    }
}
