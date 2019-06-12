<?php

namespace Swarrot;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swarrot\Broker\MessageProvider\MessageProviderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Swarrot\Processor\ProcessorInterface;
use Swarrot\Processor\ConfigurableInterface;
use Swarrot\Processor\InitializableInterface;
use Swarrot\Processor\TerminableInterface;
use Swarrot\Processor\SleepyInterface;

class Consumer
{
    /**
     * @var MessageProviderInterface
     */
    protected $messageProvider;

    /**
     * @var ProcessorInterface
     */
    protected $processor;

    /**
     * @var OptionsResolver
     */
    protected $optionsResolver;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param MessageProviderInterface $messageProvider
     * @param ProcessorInterface       $processor
     * @param OptionsResolver          $optionsResolver
     * @param LoggerInterface          $logger
     */
    public function __construct(MessageProviderInterface $messageProvider, ProcessorInterface $processor, OptionsResolver $optionsResolver = null, LoggerInterface $logger = null)
    {
        $this->messageProvider = $messageProvider;
        $this->processor = $processor;
        $this->optionsResolver = $optionsResolver ?: new OptionsResolver();
        $this->logger = $logger ?: new NullLogger();
    }

    /**
     * consume.
     *
     * @param array $options Parameters sent to the processor
     */
    public function consume(array $options = array())
    {
        $this->logger->debug('Start consuming queue.', [
            'queue' => $this->messageProvider->getQueueName(),
        ]);

        $this->optionsResolver->setDefaults(array(
            'poll_interval' => 50000,
            'queue' => $this->messageProvider->getQueueName(),
            'method' => 'consume'
        ));

        if ($this->processor instanceof ConfigurableInterface) {
            $this->processor->setDefaultOptions($this->optionsResolver);
        }

        $options = $this->optionsResolver->resolve($options);

        if ($this->processor instanceof InitializableInterface) {
            $this->processor->initialize($options);
        }

        if ($options['method'] === 'consume') {
            $this->methodConsume($options);
        } else {
            $this->methodGet($options);
        }


        if ($this->processor instanceof TerminableInterface) {
            $this->processor->terminate($options);
        }
    }

    private function methodConsume(array $options = array())
    {
        $consumerTag = uniqid();
        try {

            $this->messageProvider->consume($consumerTag, function($message) use ($options) {

                if (false === $this->processor->process($message, $options)) {
                    throw new \Exception('Stop processing');
                }

                if ($this->processor instanceof SleepyInterface) {
                    if (false === $this->processor->sleep($options)) {
                        throw new \Exception('Stop processing');
                    }
                }
            });

        } catch (\Exception $e) {}
    }

    private function methodGet(array $options = array())
    {
        while (true) {
            while (null !== $message = $this->messageProvider->get()) {
                if (false === $this->processor->process($message, $options)) {
                    break 2;
                }
            }
            if ($this->processor instanceof SleepyInterface) {
                if (false === $this->processor->sleep($options)) {
                    break;
                }
            }
            usleep($options['poll_interval']);
        }
    }

    /**
     * @return MessageProviderInterface
     */
    public function getMessageProvider()
    {
        return $this->messageProvider;
    }

    /**
     * @param MessageProviderInterface $messageProvider Message provider
     *
     * @return self
     */
    public function setMessageProvider(MessageProviderInterface $messageProvider)
    {
        $this->messageProvider = $messageProvider;

        return $this;
    }

    /**
     * @return ProcessorInterface
     */
    public function getProcessor()
    {
        return $this->processor;
    }

    /**
     * @param ProcessorInterface $processor
     *
     * @return self
     */
    public function setProcessor($processor)
    {
        $this->processor = $processor;

        return $this;
    }

    /**
     * @return OptionsResolver
     */
    public function getOptionsResolver()
    {
        return $this->optionsResolver;
    }

    /**
     * @param OptionsResolver $optionsResolver
     *
     * @return self
     */
    public function setOptionsResolver(OptionsResolver $optionsResolver)
    {
        $this->optionsResolver = $optionsResolver;

        return $this;
    }
}
