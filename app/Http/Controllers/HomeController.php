<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Category;
use App\Events\OrderCreated;
use App\Http\Middleware\Admin;
use App\Order;
use App\Product;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Psy\Util\Str;

class HomeController extends Controller
{

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $products = Product::all();
        foreach ($products as $p){
            $slug = \Illuminate\Support\Str::slug($p->__get("product_name"));
            $p->slug =$slug.$p->__get("id");
            $p->save();
        }
//        die("done");
//        $u = Auth::user();
//        $u->role =User::ADMIN_ROLE;
//        $u->save();
        if (!Cache::has("home_page")){
            $most_views = Product::orderBy("view_count", "DESC")->limit(8)->get();
            $featured = Product::orderBy("updated_at", "DESC")->limit(8)->get();
            $latest_1 = Product::orderBy("updated_at", "DESC")->limit(3)->get();
            $latest_2 = Product::orderBy("updated_at", "DESC")->offset(3)->limit(3)->get();
            //limit :lấy 3 thằng
            //offset : bỏ đi 3 thằng đầu tiên
            //offset = (page-1)*limit
            $view = view("frontend.home", [
                "most_views" => $most_views,
                "featured" => $featured,
                "latest_1" => $latest_1,
                "latest_2" => $latest_2
            ])->render();
            $now = Carbon::now();
            Cache::put("home_page", $view, $now->addMinutes(20));
        }
        return  Cache::get("home_page");
    }

    public function category(Category $category)
    {
        $products = $category->Products()->paginate(12);
        return view("frontend.category", [
            "category" => $category,
            "products" => $products
            //lấy những sản phẩm thuộc category đó
            //dùng thuận lơi cho việc nếu sau này có đổi tên category
        ]);
    }

    public function product(Product $product)
    {
        //    $products = $product->Products()->paginate(12);
        if (!session()->has("view_count_{$product->__get("id")}"))
            $product->increment("view_count");
        session(["view_count_{$product->__get("id")}" => true]);
        //đếm số lần xem sản phẩm
        //nếu f5 thì số lần view sẽ k tăng nếu session đã được lưu


        $relativeProduct = Product::with("Category")->paginate(4);
        return view("frontend.product", [
            "product" => $product,
            "relativeProducts" => $relativeProduct
        ]);
    }

    public function addToCart(Product $product,Request $request){
        $qty = $request->has("qty") && (int)$request->get("qty")>0?(int)$request->get("qty"):1;// kiểm tra qty co phai number hay khong
//        dd($qty);
        // lay qty kiem tra neu la int > 0 thi se tra ve = qty = 1
        $myCart = session()->has("my_cart") && is_array(session("my_cart"))?session("my_cart"):[];
//        dd($myCart);
        // kiem tra session neu co truong my_cart va mang my_cart neu khong co se truyen vao 1 mang rong~
        // nguyen tac lam trang gio hang se tang so luong chu khong tang san pham vao
        if(Auth::check()) {
            if (Cart::where("user_id", Auth::id())->where("is_checkout", true)->exists()) {
                $cart = Cart::where("user_id", Auth::id())->where("is_checkout", true)->first();
            } else {
                $cart = Cart::create([
                    "user_id" => Auth::id(),
                    "is_checkout" => true

                ]);
            }
        }
        $contain = false;
        foreach ($myCart as $key=>$item){ // dua vao key de lay lai doi tuong item
            if($item["product_id"] == $product->__get("id")){ // nếu sản phẩm đã có trong giỏ
                $myCart[$key]["qty"] += $qty; // nếu có thì sẽ truyền thêm vào biến qty ở trên
                $contain = true; // neu co san pham se truyen trang thai ve true
                if(Auth::check()){
                    DB::table("cart_product")->where("cart_id",$cart->__get("id"))
                        ->where("product_id",$item["product_id"])
                        ->update([
                            "qty"=>$myCart[$key]["qty"]
                        ]);
                }
                break;
            }
        }
        // dat 1 bien de kiem tra trang thai san pham co hay chua
        if(!$contain){ // nếu trả về true sẽ trả về 1 mảng mycart mới truyền vào qty và id sản phẩm hiện tại
            $myCart[] = [
                "product_id" => $product->__get("id"),
                "qty" => $qty
            ];
            if (Auth::check()){
                DB::table("cart_product")->insert([
                    "qty" => $qty,
                    "cart_id" => $cart->__get("id"),
                    "product_id" => $product->__get("id")
                ]);
            }

        }
//        dd($myCart);
        // nap lai session cũ
        session(["my_cart" => $myCart]);
        // them sản phẩm từ giỏ hàng vào database
        // cart chinh la doi tuong cart vua tao ra va se them lai vao bang trung gian
        // return redirect về trang trước
        return redirect()->back();
    }
    public function shoppingCart(){
        $myCart = session()->has("my_cart") && is_array(session("my_cart"))?session("my_cart"):[];
        $products = [];
        foreach ($myCart as $item){
            $products[] = $item["product_id"];
        }
        $grandTotal = 0;
        $products = \App\Product::find($products);
        foreach ($products as $p){
            foreach ($myCart as $item){
                if($p->__get("id") == $item["product_id"]){
                    $grandTotal += ($p->__get("price") * $item["qty"]);
                    $p->cart_qty = $item["qty"]; // them doi tuong cart_qty de foreach ra mang
                }
            }
        }
        return view("frontend.cart",[
            "products" => $products,
            "grandTotal" => $grandTotal,
        ]);
    }

    public function checkout()
    {
        $cart = Cart::where("user_id", Auth::id())
            ->where("is_checkout", true)
            ->with("getItems")
            ->firstOrFail();
        return view("frontend.checkout", [
            "cart" =>$cart
        ]);
    }
    //xu ly chuc nang thanh toan

    public function placeOrder(Request $request){
        $request->validate([
            "username"=>"required",
            "address"=>"required",
            "telephone"=>"required"
        ]);
        $cart =Cart::where("user_id", Auth::id())
            ->where("is_check", true)
            ->where("getItems")
            ->firstOrFail();
        foreach ($cart->getItems as $item){
            $grandTotal += $item->pivot->__get("qty") *$item->__get("price");
        }
        try {
            $order = Order::create([
                "user_id"=>Auth::id(),
                "username"=>$request->get("username"),
                "address"=>$request->get("adress"),
                "telephone"=>$request->get("telephone"),
                "note"=>$request->get("note"),
                "status"=>Order::PENDING
            ]);
            foreach ($cart->getItems as $item){
                DB::table("orders_products")->insert([
                    "order_id"=>$order->__get("id"),
                    "product_id"=>$item->__get("product_id"),
                    "price"=>$item->__get("price"),
                    "qty"=>$item->pivot->__get("qty"),
                ]);

            }
            event(new OrderCreated($order));
        }catch (\Exception $exception){

        }
    }
}
