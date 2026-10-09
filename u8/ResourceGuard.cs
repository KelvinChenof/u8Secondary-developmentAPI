using System;
using System.Diagnostics;
using System.Runtime.InteropServices;
namespace U8OmApi {
 // 按 PID 汇总 IPv4/IPv6 TCP 表，查询缓冲区在 finally 中释放。
 internal static class TcpOwners {
  [DllImport("iphlpapi.dll",SetLastError=true)]
  static extern uint GetExtendedTcpTable(IntPtr table,ref int size,bool order,int family,int tableClass,uint reserved);
  internal static int Parse(IntPtr table,int size,int owner,bool ipv6){
   if(size<4)throw new InvalidOperationException("TCP_TABLE_TRUNCATED");
   int count=Marshal.ReadInt32(table),row=ipv6?56:24,pidOffset=ipv6?52:20;
   if(count<0 || count>(size-4)/row)throw new InvalidOperationException("TCP_TABLE_INVALID");
   int result=0;for(int i=0;i<count;i++)if(Marshal.ReadInt32(table,4+i*row+pidOffset)==owner)result++;
   return result;
  }
  static int CountFamily(int pid,int family){
   int size=0;uint status=GetExtendedTcpTable(IntPtr.Zero,ref size,false,family,5,0);
   if(status!=122 && status!=0)throw new InvalidOperationException("TCP_TABLE_UNAVAILABLE");
   for(int attempt=0;attempt<3;attempt++){
    if(size<4||size>32*1024*1024)throw new InvalidOperationException("TCP_TABLE_SIZE_INVALID");
    IntPtr buffer=Marshal.AllocHGlobal(size);
    try{int capacity=size;status=GetExtendedTcpTable(buffer,ref size,false,family,5,0);
     if(status==0)return Parse(buffer,capacity,pid,family==23);
     if(status!=122)throw new InvalidOperationException("TCP_TABLE_READ_FAILED");
    }finally{Marshal.FreeHGlobal(buffer);}
   }
   throw new InvalidOperationException("TCP_TABLE_UNSTABLE");
  }
  internal static int Count(int pid){return CountFamily(pid,2)+CountFamily(pid,23);}
 }
 // 限制单个工作进程的 TCP、句柄和私有内存，不代表系统整体资源诊断。
 internal static class ResourceBudget {
  internal static void Check(int tcp,int handles,long bytes){
   if(tcp>=128)throw new InvalidOperationException("RESOURCE_TCP_LIMIT");
   if(handles>=10000)throw new InvalidOperationException("RESOURCE_HANDLE_LIMIT");
   if(bytes>=768L*1024*1024)throw new InvalidOperationException("RESOURCE_MEMORY_LIMIT");
  }
  internal static void Sample(string stage){
   using(Process p=Process.GetCurrentProcess()){
    int tcp=TcpOwners.Count(p.Id);
    Console.Error.WriteLine("RESOURCE stage="+stage+" pid="+p.Id+" tcp="+tcp+" handles="+p.HandleCount+" private_bytes="+p.PrivateMemorySize64);
    Check(tcp,p.HandleCount,p.PrivateMemorySize64);
   }
  }
 }
}
