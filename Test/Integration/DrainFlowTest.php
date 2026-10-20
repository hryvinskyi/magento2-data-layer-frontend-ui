<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration;

use Hryvinskyi\DataLayer\Test\Integration\ResolvesServices;
use Hryvinskyi\DataLayer\Test\Integration\TakesQueuedEvents;
use Hryvinskyi\DataLayer\Api\ServerEventDrainInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Signal\PendingSignalInterface;
use Hryvinskyi\DataLayerFrontendUi\Model\ServerEventCookie;
use Laminas\Http\Header\HeaderInterface;
use Laminas\Http\Headers;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;
use Magento\Framework\Session\SessionStartChecker;
use Magento\Framework\UrlInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\Request;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * A storefront action that queues an event tells the browser through the `hdl_events` cookie; the drain endpoint hands
 * the event over with its id and keeps it queued, so a second drain hands over the same event, until the browser
 * acknowledges the id: then the queue is empty and the cookie deleted. Neither endpoint writes a visitor row. The
 * cookie travels on any response the full-page cache will not store (a redirect after a GET included) and never on one
 * it may store. A visitor without a session gets an empty answer from both endpoints, which start no session for them.
 *
 * Requests run through the storefront application, with the request's address and method in the server variables as
 * a web server gives them (storefront observers read them there). The test application keeps cookies in `$_COOKIE`,
 * so a cookie the response sets or deletes is read there.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DrainFlowTest extends AbstractController
{
    use ResolvesServices;
    use TakesQueuedEvents;

    private const COOKIE = 'hdl_events';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        unset($_COOKIE[self::COOKIE]);
        $this->takeQueuedEvents();
        $this->pendingSignal()->discard();
        unset($_COOKIE[self::COOKIE]);
        $_COOKIE[$this->sessionCookieName()] = 'drain-flow-session';
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        unset($_COOKIE[self::COOKIE], $_COOKIE[$this->sessionCookieName()]);
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    /**
     * Adding to the cart sets the cookie; two drains without an acknowledgement hand over the same add_to_cart with the
     * same id and keep the cookie; the acknowledgement empties the queue and deletes the cookie; neither endpoint
     * writes a visitor row
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testEventIsHandedOverUntilAcknowledged(): void
    {
        $product = $this->sellableProduct('DL-DRAIN-1', 'Drained laptop', 30.0);

        $this->postForm('/checkout/cart/add/', ['product' => (string) $product->getId(), 'qty' => '1']);
        self::assertSame('1', $_COOKIE[self::COOKIE] ?? null, 'Adding to the cart signals waiting events.');

        $visitorRows = $this->visitorRowCount();
        $first = $this->drain();
        self::assertSame(['add_to_cart'], array_column(array_column($first, 'event'), 'event'));
        $second = $this->drain();
        self::assertSame($first, $second, 'An answer the page did not acknowledge is handed over again.');
        self::assertSame('1', $_COOKIE[self::COOKIE] ?? null, 'Reading the queue keeps the signal.');

        self::assertSame(1, $this->acknowledge(array_column($first, 'id')));
        self::assertArrayNotHasKey(self::COOKIE, $_COOKIE, 'The acknowledgement that empties the queue deletes it.');
        self::assertFalse($this->service(ServerEventDrainInterface::class)->hasEvents());
        self::assertSame([], $this->drain());
        self::assertSame(1, $this->acknowledge(array_column($first, 'id')), 'A repeated acknowledgement is harmless.');
        self::assertSame($visitorRows, $this->visitorRowCount(), 'Neither endpoint writes a visitor row.');
    }

    /**
     * An event queued after the page read the queue survives the acknowledgement of what it read
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testEventQueuedAfterTheReadSurvivesTheAcknowledgement(): void
    {
        $this->signIn();
        $read = $this->drain();
        self::assertSame(['login'], array_column(array_column($read, 'event'), 'event'));

        $product = $this->sellableProduct('DL-DRAIN-2', 'Drained dock', 9.0);
        $this->postForm('/checkout/cart/add/', ['product' => (string) $product->getId(), 'qty' => '1']);
        $this->acknowledge(array_column($read, 'id'));

        self::assertSame('1', $_COOKIE[self::COOKIE] ?? null, 'Events still wait.');
        self::assertSame(['add_to_cart'], array_column($this->take(), 'event'));
    }

    /**
     * A request from another site is refused by both endpoints and the waiting events stay
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testForeignOriginLeavesTheQueue(): void
    {
        $this->signIn();

        $this->post('/hryvinskyi_datalayer/events/drain/', 'https://elsewhere.example');

        $response = $this->response();
        self::assertSame(403, $response->getHttpResponseCode());
        self::assertSame('{"events":[]}', (string) $response->getBody());
        self::assertTrue($this->service(ServerEventDrainInterface::class)->hasEvents());

        $ids = array_column($this->service(ServerEventDrainInterface::class)->pending(), 'id');
        $body = (string) json_encode(['ids' => $ids]);
        $this->request('POST', '/hryvinskyi_datalayer/events/acknowledge/', 'https://elsewhere.example', [], $body);
        self::assertSame(403, $this->response()->getHttpResponseCode());
        self::assertTrue($this->service(ServerEventDrainInterface::class)->hasEvents());
    }

    /**
     * An event queued during a GET does not set the cookie there; the next storefront POST of a visitor with a session
     * does
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testEventQueuedOnAGetIsSignalledByTheNextPost(): void
    {
        $request = $this->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->setMethod('GET');
        $this->signIn();
        self::assertArrayNotHasKey(self::COOKIE, $_COOKIE, 'A GET response never carries the cookie.');

        $_COOKIE[$this->sessionCookieName()] = 'drain-flow-session';
        $this->postForm('/checkout/cart/add/', ['product' => '999999', 'qty' => '1']);

        self::assertSame('1', $_COOKIE[self::COOKIE] ?? null, 'The next POST signals the waiting sign-in.');
        self::assertSame(['login'], array_column($this->take(), 'event'));
    }

    /**
     * A visitor without a session cookie: both endpoints answer that nothing waits, delete a stale cookie, never read
     * the queue, and refuse to start a session for the request
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testVisitorWithoutASessionGetsNothingAndNoSession(): void
    {
        $this->signIn();
        unset($_COOKIE[$this->sessionCookieName()]);
        $_COOKIE[self::COOKIE] = '1';

        self::assertSame([], $this->drain());

        self::assertArrayNotHasKey(self::COOKIE, $_COOKIE, 'The drain deletes the stale cookie.');
        self::assertTrue($this->service(ServerEventDrainInterface::class)->hasEvents(), 'The queue is not read.');
        self::assertFalse(
            $this->fresh(SessionStartChecker::class)->check(),
            'No session may start for the drain of a visitor without one.'
        );

        $_COOKIE[self::COOKIE] = '1';
        $ids = array_column($this->service(ServerEventDrainInterface::class)->pending(), 'id');
        self::assertSame(0, $this->acknowledge($ids));
        self::assertArrayNotHasKey(self::COOKIE, $_COOKIE, 'The acknowledgement deletes the stale cookie.');
        self::assertTrue($this->service(ServerEventDrainInterface::class)->hasEvents(), 'The queue is not changed.');
        self::assertFalse(
            $this->fresh(SessionStartChecker::class)->check(),
            'No session may start for the acknowledgement of a visitor without one.'
        );

        $_COOKIE[$this->sessionCookieName()] = 'drain-flow-session';
        self::assertTrue($this->fresh(SessionStartChecker::class)->check(), 'A visitor with a session keeps it.');
    }

    /**
     * An event queued during a GET that redirects (an account confirmation signs the customer in) is signalled on the
     * redirect, which no page cache stores
     *
     * @magentoDataFixture Magento/Customer/_files/unconfirmed_customer.php
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testEventQueuedDuringARedirectingGetIsSignalled(): void
    {
        $customer = $this->fresh(Customer::class);
        $customer->setWebsiteId(1);
        $customer->loadByEmail('unconfirmedcustomer@example.com');
        $key = $customer->getConfirmation();
        $customerId = $customer->getId();
        self::assertIsString($key);
        self::assertIsNumeric($customerId);

        $this->request('GET', '/customer/account/confirm/', $this->storeOrigin(), [
            'id' => (string) $customerId,
            'key' => $key,
        ]);

        self::assertSame(302, $this->response()->getHttpResponseCode());
        self::assertSame('1', $_COOKIE[self::COOKIE] ?? null, 'The redirect carries the signal.');
        self::assertContains('login', array_column($this->take(), 'event'));
    }

    /**
     * A page the full-page cache may store never carries the signal, even for events queued during it; they wait for
     * the next response that can tell. The page must be rendered, not served from a full-page cache an earlier run
     * left behind, so that cache starts empty.
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoCache full_page enabled
     * @return void
     */
    public function testCacheablePageNeverCarriesTheSignal(): void
    {
        $this->service(TypeListInterface::class)->cleanType('full_page');
        $this->signIn();

        $this->request('GET', '/', $this->storeOrigin());

        self::assertSame(200, $this->response()->getHttpResponseCode());
        self::assertArrayNotHasKey(self::COOKIE, $_COOKIE, 'A cacheable page never carries the cookie.');
        self::assertTrue($this->service(ServerEventDrainInterface::class)->hasEvents());
    }

    /**
     * Queue the fixture customer's sign-in as the storefront does
     *
     * @return void
     */
    private function signIn(): void
    {
        $customer = $this->fresh(Customer::class);
        $this->service(CustomerResource::class)->load($customer, 1);
        $this->service(ManagerInterface::class)->dispatch('customer_login', ['customer' => $customer]);
    }

    /**
     * Post to the drain endpoint from the store's own page and read the entries it answers
     *
     * @return list<array{id: string, event: array<mixed>}>
     */
    private function drain(): array
    {
        $this->post('/hryvinskyi_datalayer/events/drain/', $this->storeOrigin());

        $body = $this->answer();
        self::assertSame(['events'], array_keys($body));
        self::assertIsArray($body['events']);
        $entries = [];
        foreach ($body['events'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['id'] ?? null);
            self::assertIsArray($entry['event'] ?? null);
            $entries[] = ['id' => $entry['id'], 'event' => $entry['event']];
        }

        return $entries;
    }

    /**
     * Post ids to the acknowledge endpoint from the store's own page
     *
     * @param list<string> $ids
     * @return int The number of ids the endpoint read
     */
    private function acknowledge(array $ids): int
    {
        $this->request(
            'POST',
            '/hryvinskyi_datalayer/events/acknowledge/',
            $this->storeOrigin(),
            [],
            (string) json_encode(['ids' => $ids])
        );

        $body = $this->answer();
        self::assertIsInt($body['acknowledged'] ?? null);

        return $body['acknowledged'];
    }

    /**
     * Take the waiting events as the page does: drain, then acknowledge every id handed over
     *
     * @return list<array<mixed>> The events
     */
    private function take(): array
    {
        $entries = $this->drain();
        $this->acknowledge(array_column($entries, 'id'));

        return array_column($entries, 'event');
    }

    /**
     * Read the last answer of one of the queue's endpoints: a 200 that no cache may store, with a JSON object
     *
     * @return array<mixed>
     */
    private function answer(): array
    {
        $response = $this->response();
        self::assertSame(200, $response->getHttpResponseCode());
        $cacheControl = $response->getHeader('Cache-Control');
        self::assertInstanceOf(HeaderInterface::class, $cacheControl);
        self::assertSame('no-store', $cacheControl->getFieldValue());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * Post a storefront form with the visitor's form key
     *
     * @param string $path
     * @param array<string, string> $fields
     * @return void
     */
    private function postForm(string $path, array $fields): void
    {
        $fields['form_key'] = $this->service(FormKey::class)->getFormKey();
        $this->post($path, $this->storeOrigin(), $fields);
    }

    /**
     * Post a request through the storefront application
     *
     * @param string $path
     * @param string $origin
     * @param array<string, string> $fields
     * @return void
     */
    private function post(string $path, string $origin, array $fields = []): void
    {
        $this->request('POST', $path, $origin, $fields);
    }

    /**
     * Send a request through the storefront application
     *
     * @param string $method
     * @param string $path
     * @param string $origin
     * @param array<string, string> $fields The request's parameters, posted for a POST
     * @param string $content The request's raw body
     * @return void
     */
    private function request(
        string $method,
        string $path,
        string $origin,
        array $fields = [],
        string $content = ''
    ): void {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);
        $objectManager->removeSharedInstance(ResponseInterface::class);
        // a web request starts with nothing written to the signal yet
        $this->service(ServerEventCookie::class)->_resetState();
        $request = $this->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->setMethod($method);
        $request->setPostValue($method === 'POST' ? $fields : []);
        $request->setContent($content);
        $request->clearParams();
        $request->setParams($fields);
        $headers = $request->getHeaders();
        self::assertInstanceOf(Headers::class, $headers);
        $headers->clearHeaders();
        $headers->addHeaders(['Origin' => $origin]);
        $request->setDispatched(false);
        $request->setModuleName('');
        $request->setControllerName('');
        $request->setActionName('');
        $request->setRequestUri($path);
        $request->setPathInfo($path);
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        $this->_getBootstrap()->runApp();
    }

    /**
     * Get the signal held until the response is known
     *
     * @return PendingSignalInterface
     */
    private function pendingSignal(): PendingSignalInterface
    {
        return $this->service(PendingSignalInterface::class);
    }

    /**
     * Get the last response
     *
     * @return Response
     */
    private function response(): Response
    {
        $response = Bootstrap::getObjectManager()->get(ResponseInterface::class);
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * Get the origin of the store's own pages
     *
     * @return string
     */
    private function storeOrigin(): string
    {
        $baseUrl = $this->service(UrlInterface::class)->getBaseUrl();
        self::assertSame(1, preg_match('~^https?://[^/]+~', $baseUrl, $match));

        return $match[0];
    }

    /**
     * Get the name of the storefront session cookie
     *
     * @return string
     */
    private function sessionCookieName(): string
    {
        return (string) $this->service(SessionConfigInterface::class)->getName();
    }

    /**
     * Count the stored visitor rows
     *
     * @return int
     */
    private function visitorRowCount(): int
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $count = $connection->fetchOne(
            $connection->select()->from($resource->getTableName('customer_visitor'), ['COUNT(*)'])
        );

        return is_numeric($count) ? (int) $count : -1;
    }

    /**
     * Create an enabled simple product that can be added to a cart without stock
     *
     * @param string $sku
     * @param string $name
     * @param float $price
     * @return Product
     */
    private function sellableProduct(string $sku, string $name, float $price): Product
    {
        $product = $this->fresh(Product::class);
        $product->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId(4)
            ->setSku($sku)
            ->setName($name)
            ->setPrice($price)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setWebsiteIds([1])
            ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 0]);
        $this->service(ProductResource::class)->save($product);

        return $product;
    }
}
