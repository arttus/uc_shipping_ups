<?php

namespace Drupal\uc_shipping_ups\Plugin\Ubercart\ShippingQuote;

use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\uc_order\OrderInterface;
use Drupal\uc_quote\ShippingQuotePluginBase;
use Drupal\uc_shipping_ups\UPSUtilities;

/**
 * Provides a flat rate shipping quote plugin.
 *
 * @UbercartShippingQuote(
 *   id = "upsratebase",
 *   admin_label = @Translation("UPS Rate Base")
 * )
 */
class UPSRateBase extends ShippingQuotePluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array(
      'qty' =>1,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $fields = ['' => $this->t('- None -')];
    $result = \Drupal::entityQuery('field_config')
      ->condition('field_type', 'number')
      ->execute();
    foreach (FieldConfig::loadMultiple($result) as $field) {
      $fields[$field->getName()] = $field->label();
    }
    $form['qty'] = array(
      '#type' => 'number',
      '#title' => $this->t('Number of product can fit a package'),
      '#default_value' => $this->configuration['qty'],
      '#required' => TRUE,
    );
    
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['base_rate'] = $form_state->getValue('base_rate');
    $this->configuration['product_rate'] = $form_state->getValue('product_rate');
    $this->configuration['field'] = $form_state->getValue('field');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('UPS');
  }

  /**
   * {@inheritdoc}
   */
  public function getDisplayLabel($label) {
    // Start with logo as required by the UPS terms of service.
    $build['image'] = array(
      '#theme' => 'image',
      '#uri' => drupal_get_path('module', 'uc_shipping_ups') . '/images/uc_shipping_ups_logo.jpg',
      '#alt' => $this->t('UPS logo'),
      '#attributes' => array('class' => array('ups-logo')),
    );
    // Add the UPS service name.
    $build['label'] = array(
      '#plain_text' => $this->t('@service Rate', ['@service' => $label]),
    );
    // Add package information.
    $build['packages'] = array(
      '#plain_text' => ' (' . \Drupal::translation()->formatPlural(count($packages), '1 package', '@count packages') . ')',
    );

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuotes(OrderInterface $order) {
    $rate = 0;
    $client = \Drupal::httpClient();
    $store_config = \Drupal::config('uc_store.settings');
    $origin = $store_config->get('address');
    $destination = $order->getAddress('delivery');
    $ups_config = \Drupal::config('uc_shipping_ups.settings');

        $packages = $this->packageProducts($order->products, array($order->getAddress('delivery')));

   foreach ($packages as $key => $ship_packages) {
    $orig = $origin;
    foreach (array_keys(array_filter($ups_config->get('services'))) as $ups_service) {

      $request = $this->shipping_schema($ship_packages, $orig, $destination, $ups_service);
      $resp = $client->request('post', $ups_config->get('connection_address') . 'Rate', array(
        'body' => $request,
      ));
      $response = simplexml_load_string($resp->getBody()->getContents());
     if (isset($response->RatedShipment)) {
        $charge = $response->RatedShipment->TotalCharges;
        if (isset($response->RatedShipment->NegotiatedRates)) {
          $charge = $response->RatedShipment->NegotiatedRates->NetSummaryCharges->GrandTotal;
        }
        $store_currency = $store_config->get('currency');
        $cur = $store_currency['code'];
        
        if (!isset($charge->CurrencyCode) || (string)$charge->CurrencyCode == $cur) {
          // Markup rate before customer sees it.
          if (!isset($quotes[$ups_service]['rate'])) {
            $quotes[$ups_service]['rate'] = 0;
          }
          $rate_amount = $response->RatedShipment->TotalCharges->MonetaryValue;
          $rate = $rate_amount->__toString();
        
  
    }
  }

        
      }
    }
    


    return [$rate];
  }
  public function access_request() {
$ups_config = \Drupal::config('uc_shipping_ups.settings');
  $access = $ups_config->get('access_license');
  $user = $ups_config->get('user_id');
  $password = $ups_config->get('password');
  return '<?xml version="1.0" ?>
<AccessRequest xml:lang="en-US">
  <AccessLicenseNumber>'.$access.'</AccessLicenseNumber>
  <UserId>'.$user.'</UserId>
  <Password>'.$password.'</Password>
</AccessRequest>
';
}

  /**
   * Organizes products into packages for shipment.
   *
   * @param OrderProduct[] $products
   *   An array of product objects as they are represented in the cart or order.
   * @param Address[] $addresses
   *   Reference to an array of addresses which are the pickup locations of each
   *   package. They are determined from the shipping addresses of their
   *   component products.
   *
   * @return array
   *   Array of packaged products. Packages are separated by shipping address and
   *   weight or quantity limits imposed by the shipping method or the products.
   */
  protected function packageProducts(array $products, array $addresses) {
    $last_key = 0;
    $packages = array();
    $ups_config = \Drupal::config('uc_shipping_ups.settings');

    if ($ups_config->get('all_in_one') && count($products) > 1) {
      // "All in one" packaging strategy.
      // Only need to do this if more than one product line item in order.
      
      $packages[$last_key] = array(0 => $this->newPackage());
      foreach ($products as $product) {
        if ($product->nid->target_id) {
          // Packages are grouped by the address from which they will be
          // shipped. We will keep track of the different addresses in an array
          // and use their keys for the array of packages.

          $key = NULL;
          $address = uc_quote_get_default_shipping_address($product->nid->value);
          foreach ($addresses as $index => $value) {
            if ($address->isSamePhysicalLocation($value)) {
              // This is an existing address.
              $key = $index;
              break;
            }
          }

          if (!isset($key)) {
            // This is a new address. Increment the address counter $last_key
            // instead of using [] so that it can be used in $packages and
            // $addresses.
            $addresses[++$last_key] = $address;
            $key = $last_key;
            $packages[$key] = array(0 => $this->newPackage());
          }
        }

        // Grab some product properties directly from the (cached) product
        // data. They are not normally available here because the $product
        // object is being read out of the $order object rather than from
        // the database, and the $order object only carries around a limited
        // number of product properties.
        $temp = node_load($product->nid->target_id);

        $product->length = $temp->length->value;
        $product->width = $temp->width->value;
        $product->height = $temp->height->value;
        $product->length_units = $temp->length_units;
        $product->ups['container'] = isset($temp->ups['container']) ? $temp->ups['container'] : 'VARIABLE';

        $packages[$key][0]->price += $product->price * $product->qty;
        $packages[$key][0]->weight += $product->weight * $product->qty * uc_weight_conversion($product->weight_units, 'lb');

      }
      foreach ($packages as $key => $package) {
        $packages[$key][0]->pounds = floor($package[0]->weight);
        $packages[$key][0]->ounces = LB_TO_OZ * ($package[0]->weight - $packages[$key][0]->pounds);
        $packages[$key][0]->container = 'VARIABLE';
        $packages[$key][0]->size = 'REGULAR';
        // Packages are "machinable" if heavier than 6oz. and less than 35lbs.
        $packages[$key][0]->machinable = (
          ($packages[$key][0]->pounds == 0 ? $packages[$key][0]->ounces >= 6 : TRUE) &&
          $packages[$key][0]->pounds <= 35 &&
          ($packages[$key][0]->pounds == 35 ? $packages[$key][0]->ounces == 0 : TRUE)
        );
        $packages[$key][0]->qty = 1;
      }
    }
    else {
      // !$ups_config->get('all_in_one') || count($products) = 1
      // "Each in own" packaging strategy, or only one product line item in order.
      foreach ($products as $product) {
        if ($product->nid) {
      
          $address = uc_quote_get_default_shipping_address($product->nid->target_id);
          if (in_array($address, $addresses)) {
            drupal_set_message('just 3');
            // This is an existing address.
            foreach ($addresses as $index => $value) {
              if ($address == $value) {
                $key = $index;
                break;
              }
            }
          }
          else {
            // This is a new address.
            $addresses[++$last_key] = $address;
            $key = $last_key;
          }
        }
        if (!isset($product->pkg_qty) || !$product->pkg_qty) {
          $product->pkg_qty = 1;
        }
        $num_of_pkgs = ceil(($product->qty->value / $this->configuration['qty']));
        if ($num_of_pkgs) {
          $package = $this->newPackage();
          

          $package->description = $product->model->value;
          
          $weight = $product->weight->value * $product->pkg_qty;
          switch ($product->weight_units) {
            case 'g':
              // Convert to kg and fall through.
              $weight = $weight * G_TO_KG;
            case 'kg':
              // Convert to lb and fall through.
              $weight = $weight * KG_TO_LB;
            case 'lb':
              $package->pounds = floor($weight);
              $package->ounces = LB_TO_OZ * ($weight - $package->pounds);
              break;
            case 'oz':
              $package->pounds = floor($weight * OZ_TO_LB);
              $package->ounces = $weight - $package->pounds * LB_TO_OZ;
              break;
          }

          // Grab some product properties directly from the (cached) product
          // data. They are not normally available here because the $product
          // object is being read out of the $order object rather than from
          // the database, and the $order object only carries around a limited
          // number of product properties.
          $temp = node_load($product->nid->target_id);
          $product->length = $temp->length;
          $product->width = $temp->width;
          $product->height = $temp->height;
          $product->length_units = $temp->length_units;
          $product->ups['container'] = isset($temp->ups['container']) ? $temp->ups['container'] : 'VARIABLE';

          $package->container = $product->ups['container'];
          $length_conversion = uc_length_conversion($product->length_units, 'in');
          $package->length = max($product->length, $product->width) * $length_conversion;
          $package->width = min($product->length, $product->width) * $length_conversion;
          $package->height = $product->height * $length_conversion;
          if ($package->length < $package->height) {
            list($package->length, $package->height) = array($package->height, $package->length);
          }
          $package->girth = 2 * $package->width + 2 * $package->height;
          $package->size = $package->length <= 12 ? 'REGULAR' : 'LARGE';
          $package->machinable = (
            $package->length >= 6 && $package->length <= 34 &&
            $package->width >= 0.25 && $package->width <= 17 &&
            $package->height >= 3.5 && $package->height <= 17 &&
            ($package->pounds == 0 ? $package->ounces >= 6 : TRUE) &&
            $package->pounds <= 35 &&
            ($package->pounds == 35 ? $package->ounces == 0 : TRUE)
          );
          $package->price = $product->price->value * $product->qty->value;

          $package->qty = $num_of_pkgs;
       
          $packages[$key][] = $package;

        }
        $remaining_qty = $product->qty->value % $product->pkg_qty;
        if ($remaining_qty) {
          $package = $this->newPackage();
          $package->description = $product->model->value;
          $weight = $product->weight * $remaining_qty;
          switch ($product->weight_units) {
            case 'g':
              // Convert to kg and fall through.
              $weight = $weight * G_TO_KG;
            case 'kg':
              // Convert to lb and fall through.
              $weight = $weight * KG_TO_LB;
            case 'lb':
              $package->pounds = floor($weight);
              $package->ounces = LB_TO_OZ * ($weight - $package->pounds);
              break;
            case 'oz':
              $package->pounds = floor($weight * OZ_TO_LB);
              $package->ounces = $weight - $package->pounds * LB_TO_OZ;
              break;
          }
          $package->container = $product->ups['container'];
          $length_conversion = uc_length_conversion($product->length_units, 'in');
          $package->length = max($product->length, $product->width) * $length_conversion;
          $package->width = min($product->length, $product->width) * $length_conversion;
          $package->height = $product->height * $length_conversion;
          if ($package->length < $package->height) {
            list($package->length, $package->height) = array($package->height, $package->length);
          }
          $package->girth = 2 * $package->width + 2 * $package->height;
          $package->size = $package->length <= 12 ? 'REGULAR' : 'LARGE';
          $package->machinable = (
            $package->length >= 6 && $package->length <= 34 &&
            $package->width >= 0.25 && $package->width <= 17 &&
            $package->height >= 3.5 && $package->height <= 17 &&
            ($package->pounds == 0 ? $package->ounces >= 6 : TRUE) &&
            $package->pounds <= 35 &&
          ($package->pounds == 35 ? $package->ounces == 0 : TRUE)
          );
          $package->price = $product->price * $remaining_qty;
          $package->qty = 1;
          $packages[$key][] = $package;

        }
      }
    }
    return $packages;
  }

  /**
   * Pseudo-constructor to set default values of a package.
   */
  protected function newPackage() {
    $package = new \stdClass();

    $package->price = 0;
    $package->qty = 1;
    $package->pkg_type = '02';

    $package->weight = 0;
    $package->weight_units = 'lb';

    $package->length = 0;
    $package->width = 0;
    $package->height = 0;
    $package->length_units = 'in';

    return $package;
  }

  /**
   * Modifies the rate received from UPS before displaying to the customer.
   *
   * @param $rate
   *   Shipping rate without any rate markup.
   *
   * @return
   *   Shipping rate after markup.
   */
  protected function rateMarkup($rate) {
    $ups_config = \Drupal::config('uc_shipping_ups.settings');
    $markup = trim($ups_config->get('rate_markup'));
    $type   = $ups_config->get('rate_markup_type');

    if (is_numeric($markup)) {
      switch ($type) {
        case 'percentage':
          return $rate + $rate * floatval($markup) / 100;
        case 'multiplier':
          return $rate * floatval($markup);
        case 'currency':
          return $rate + floatval($markup);
      }
    }
    else {
      return $rate;
    }
  }

  /**
   * Modifies the weight of shipment before sending to UPS for a quote.
   *
   * @param $weight
   *   Shipping weight without any weight markup.
   *
   * @return
   *   Shipping weight after markup.
   */
  protected function weightMarkup($weight) {
    $ups_config = \Drupal::config('uc_shipping_ups.settings');
    $markup = trim($ups_config->get('weight_markup'));
    $type   = $ups_config->get('weight_markup_type');

    if (is_numeric($markup)) {
      switch ($type) {
        case 'percentage':
          return $weight + $weight * floatval($markup) / 100;

        case 'multiplier':
          return $weight * floatval($markup);

        case 'mass':
          return $weight + floatval($markup);
      }
    }
    else {
      return $weight;
    }
  }

 public function shipping_schema($packages, $origin, $destination, $ups_service) {
    $store_config = \Drupal::config('uc_store.settings');
    $address = $store_config->get('address');
    $ups_config = \Drupal::config('uc_shipping_ups.settings');
  $store['name'] = uc_store_name();
  $store['owner'] = $store_config->get('name');
  $store['email'] = $store_config->get('mail');
  $store['email_from'] = $store_config->get('mail');
  $store['phone'] = $store_config->get('phone');
  $store['fax'] = $store_config->get('fax');
  $store['street1'] = $address['street1'];
  $store['street2'] = $address['street2'];
  $store['city'] = $address['city'];
  $store['zone'] = $address['zone'];
  $store['postal_code'] = $address['postal_code'];
  $store['country'] = $address['country'];

  $account = $ups_config->get('shipper_number');
  $ua = explode(' ', $_SERVER['HTTP_USER_AGENT']);
  $user_agent = $ua[0];

  $services = UPSUtilities::services();
  $service = array('code' => $ups_service, 'description' => $services[$ups_service]);

  $pkg_types = UPSUtilities::packageTypes();

  $shipper_zone = $store['zone'];
  $shipper_country = $store['country'];
  $shipper_zip = $store['postal_code'];
  $shipto_zone = $destination->zone;
  $shipto_country = $destination->country;
  $shipto_zip = $destination->postal_code;
  $shipfrom_zone = $origin['zone'];
  $shipfrom_country = $origin['country'];
  $shipfrom_zip = $origin['postal_code'];

  $ups_units = $ups_config->get('unit_system');
  switch ($ups_units) {
    case 'in':
      $units = 'LBS';
      $unit_name = 'Pounds';
      break;
    case 'cm':
      $units = 'KGS';
      $unit_name = 'Kilograms';
      break;
  }

  $shipment_weight = 0;
  $package_schema = '';
  foreach ($packages as $package) {
    // Determine length conversion factor and weight conversion factor
    // for this shipment.
    $length_factor = uc_length_conversion($package->length_units, $ups_config->get('unit_system'));
    switch ($ups_units) {
      case 'in':
        $weight_factor = uc_weight_conversion($package->weight_units, 'lb');
        break;
      case 'cm':
        $weight_factor = uc_weight_conversion($package->weight_units, 'kg');
        break;
    }

    // Loop over quantity of packages in this shipment.
    $qty = $package->qty;

    for ($i = 0; $i < $qty; $i++) {
      // Build XML for this package.
      $package_type = array('code' => $package->pkg_type, 'description' => $pkg_types[$package->pkg_type]);
      $package_schema .= "<Package>";
      $package_schema .=   "<PackagingType>";
      $package_schema .=     "<Code>" . $package_type['code'] . "</Code>";
      $package_schema .=   "</PackagingType>";
      if ($package->pkg_type == '02' && $package->length && $package->width && $package->height) {
        if ($package->length < $package->width) {
          list($package->length, $package->width) = array($package->width, $package->length);
        }
        $package_schema .= "<Dimensions>";
        $package_schema .=   "<UnitOfMeasurement>";
        $package_schema .=     "<Code>" . $ups_config->get('unit_system') . "</Code>";
        $package_schema .=   "</UnitOfMeasurement>";
        $package_schema .=   "<Length>" . number_format($package->length * $length_factor, 2, '.', '') . "</Length>";
        $package_schema .=   "<Width>" . number_format($package->width * $length_factor, 2, '.', '') . "</Width>";
        $package_schema .=   "<Height>" . number_format($package->height * $length_factor, 2, '.', '') . "</Height>";
        $package_schema .= "</Dimensions>";
      }

      $weight = max(1, $package->weight * $weight_factor);
      $shipment_weight += $weight;
      $package_schema .=   "<PackageWeight>";
      $package_schema .=     "<UnitOfMeasurement>";
      $package_schema .=       "<Code>" . $units . "</Code>";
      $package_schema .=       "<Description>" . $unit_name . "</Description>";
      $package_schema .=     "</UnitOfMeasurement>";
      $package_schema .=     "<Weight>" . number_format($weight, 1, '.', '') . "</Weight>";
      $package_schema .=   "</PackageWeight>";

      $size = $package->length * $length_factor + 2 * $length_factor * ($package->width + $package->height);
      if ($size > 130 && $size <= 165) {
        $package_schema .= "<LargePackageIndicator/>";
      }

      if ($ups_config->get('insurance')) {
          $store_currency = $store_config->get('currency');
        $cur = $store_currency['code'];
        $package_schema .= "<PackageServiceOptions>";
        $package_schema .=   "<InsuredValue>";
        $package_schema .=     "<CurrencyCode>" . (string)$cur . "</CurrencyCode>";
        $package_schema .=     "<MonetaryValue>" . $package->price . "</MonetaryValue>";
        $package_schema .=   "</InsuredValue>";
        $package_schema .= "</PackageServiceOptions>";
      }
      $package_schema .= "</Package>";
    }
  }

  $schema = $this->access_request() . "
<?xml version=\"1.0\" ?>
<RatingServiceSelectionRequest xml:lang=\"en-US\">
  <Request>
    <TransactionReference>
      <CustomerContext>Complex Rate Request</CustomerContext>
      <XpciVersion>1.0001</XpciVersion>
    </TransactionReference>
    <RequestAction>Rate</RequestAction>
    <RequestOption>rate</RequestOption>
  </Request>
  <PickupType>
    <Code>" . $ups_config->get('pickup_type') . "</Code>
  </PickupType>
  <CustomerClassification>
    <Code>" . $ups_config->get('classification') . "</Code>
  </CustomerClassification>
  <Shipment>
    <Shipper>
      <ShipperNumber>" . $ups_config->get('shipper_number') . "</ShipperNumber>
      <Address>
        <City>" . $store['city'] . "</City>
        <StateProvinceCode>$shipper_zone</StateProvinceCode>
        <PostalCode>$shipper_zip</PostalCode>
        <CountryCode>$shipper_country</CountryCode>
      </Address>
    </Shipper>
    <ShipTo>
      <Address>
      <City>Corado</City>
      <StateProvinceCode>".$shipto_zone."</StateProvinceCode>
        <PostalCode>".$shipto_zip."</PostalCode>
        <CountryCode>".$shipto_country."</CountryCode>
      ";
      if ($ups_config->get('residential_quotes')) {
        $schema .= "<ResidentialAddressIndicator/>
      ";
      }
      $schema .= "</Address>
    </ShipTo>
    <ShipFrom>
      <Address>
        <StateProvinceCode>".$shipper_zone."</StateProvinceCode>
        <PostalCode>".$shipper_zip."</PostalCode>
        <CountryCode>".$shipper_country."</CountryCode>
      </Address>
    </ShipFrom>
    <ShipmentWeight>
      <UnitOfMeasurement>
        <Code>$units</Code>
        <Description>$unit_name</Description>
      </UnitOfMeasurement>
      <Weight>" . number_format($shipment_weight, 1, '.', '') . "</Weight>
    </ShipmentWeight>
    <Service>
      <Code>{$service['code']}</Code>
      <Description>{$service['description']}</Description>
    </Service>
    ";
    $schema .= $package_schema;
    if ($ups_config->get('negotiated_rates')) {
      $schema .= "<RateInformation>
          <NegotiatedRatesIndicator/>
        </RateInformation>";
    }
  $schema .= "</Shipment>
</RatingServiceSelectionRequest>";

  return $schema;

}

}
