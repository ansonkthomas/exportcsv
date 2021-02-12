<?php

namespace App\Command;

use App\Entity\ProductData;
use App\Repository\ProductDataRepository;
use App\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Doctrine\ORM\EntityManagerInterface;


class ImportProductss extends Command
{
    protected static $defaultName = 'app:import-products';
    const PRODUCT_DETAILS_FILE_LOCATION = '/srv/exportcsv/';

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Import product data')->setHelp('This command fetch the product data from a CSV file and save to the DB');
        $this->addArgument('productFile', InputArgument::REQUIRED, 'The csv file which has the product details');
        $this->addArgument('mode', InputArgument::OPTIONAL, 'The csv file which has the product details');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln([
            'Import Product Data',
            '===================='
        ]);
        $fileName = $input->getArgument('productFile');
        $mode = $input->getArgument('mode');

        //Get the file details
        $fileLocation = self::PRODUCT_DETAILS_FILE_LOCATION . $fileName;
        $output->writeln(['File Name: ' . $fileName, '']);

        //Decide the run mode to insert the vakues into DB or not
        $testMode = false;
        if ($mode && $mode == 'test') {
            $testMode = true;
            $output->writeln(['Runs in test mode', '']);
        }

        if ($this->checkFileAndExtension($output, $fileLocation)) {
            //Get existing product codes in database
            $productCodes = $this->getProductCodes($this->entityManager);
            $this->parseCSVFile($output, $this->entityManager, $productCodes, $fileLocation, $testMode);

        } else {
            $output->writeln('The file does not exists or invalid file type');
        }

        return Command::SUCCESS;
    }

    /*
     * Parse the csv file and save to DB
     */
    private function parseCSVFile(OutputInterface $output, EntityManagerInterface $entityManager, $productCodes, string $fileLocation, bool $testMode): void
    {
        $i = 0;
        if (($handle = fopen($fileLocation, "r")) !== FALSE) {
            //Key values to represent the csv row as an associative array.
            //If there is any change in the order of columns in CSV file, just make the same order change here
            $columns = array('product_code', 'product_name', 'product_description', 'stock', 'cost_in_gbp', 'discontinued');
            while (($product = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $columnCount = count($product);
                if ($i != 0) {
                    //Product should be of 6 properties. If not its not a valid product
                    if (count($product) == count($columns)) {
                        //Create an associative array of product. Easier for future modifications
                        $product = array_combine($columns, $product);

                        //Validate the product
                        $isValidProduct = $this->validateProduct($output, $productCodes, $product);

                        if ($isValidProduct) {
                            //The product is valid. Save the product
                            $productCodes[] = $product['product_code'];
                            $setDiscontinuedDate = ($product['discontinued'] == 'yes') ? true : false;
                            if (!$testMode) {
                                $this->saveProductData($entityManager, $product, $setDiscontinuedDate);
                            }
                        } else {
                            //The product is not valid. Iterate the next product
                            continue;
                        }
                    } else {
                        //Product should be of 6 properties
                        $output->writeln('Unable to parse the product: Name => ' . $product[1] . ', Reason: => Product details are missing');
                    }
                }
                $i++;
            }
            fclose($handle);
        } else {
            $output->writeln('Unable to open the file');
        }
    }

    /*
     * Check the file exists in the location and the type
     */
    private function checkFileAndExtension(OutputInterface $output, string $fileLocation): bool
    {
        return (file_exists($fileLocation) && pathinfo($fileLocation, PATHINFO_EXTENSION) == 'csv') ? true : false;
    }

    /*
     * Validate each products in csv
     */
    private function validateProduct(OutputInterface $output, $productCodes, $product): bool
    {
        $isValid = true;
        $code = $product['product_code'];
        $name = $product['product_name'];
        $format = 'Unable to parse the product: Name => %s, Code: %s, Reason: => %s';

        //Check the product code already exists
        if (in_array($code, $productCodes)) {
            $output->writeln(sprintf($format, $name, $code, 'Duplicate product code'));
            $isValid = false;
        }

        //Check stock value is empty or string
        $stock = intval($product['stock']);
        if (!$stock || $stock < Settings::STOCK_LOWER_LIMIT) {
            $output->writeln(sprintf($format, $name, $code, 'Stock may be empty, invalid or less than ' . Settings::STOCK_LOWER_LIMIT));
            $isValid = false;
        }

        //Check price is empty or string
        $price = floatval($product['cost_in_gbp']);
        if (!$price || $price < Settings::PRICE_LOWER_LIMIT || $price > Settings::PRICE_HIGHER_LIMIT) {
            $output->writeln(sprintf($format, $name, $code, 'Price may be empty, invalid, less than £' . Settings::PRICE_LOWER_LIMIT . ' or greater than £' . Settings::PRICE_HIGHER_LIMIT));
            $isValid = false;
        }

        return $isValid;
    }

    private function getProductCodes(EntityManagerInterface $entityManager): array
    {
        $products = $entityManager->getRepository(ProductData::class)->findAll();
        $productCodes = array();
        foreach ($products as $product) {
            $productCodes[] = $product->getCode();
        }

        return $productCodes;
    }

    /*
     * Save product data to the DB
     */
    private function saveProductData(EntityManagerInterface $entityManager, $product, bool $setDiscontinuedDate): void
    {
        $dateTime = new \DateTime();
        $productData = new ProductData();
        $productData->setName($product['product_code']);
        $productData->setDescription($product['product_description']);
        $productData->setCode($product['product_code']);
        $productData->setStock($product['stock']);
        $productData->setPrice($product['cost_in_gbp']);
        $productData->setDtmAdded($dateTime);
        if ($setDiscontinuedDate) {
            $productData->setDtmIsContinued($dateTime);
        }
        $productData->setStmTimestamp($dateTime);
        $entityManager->persist($productData);
        $entityManager->flush();
    }


}
